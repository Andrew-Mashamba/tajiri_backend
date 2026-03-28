<?php

namespace App\Services;

use App\Models\FcmToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class FcmNotificationService
{
    /**
     * Send notification to a single user (writes to DB + sends FCM push).
     */
    public static function sendToUser(int $userId, string $type, array $data = [], ?string $title = null, ?string $body = null): void
    {
        try {
            // Write to notifications table
            if (\Schema::hasTable("notifications")) {
                DB::table("notifications")->insert([
                    "id" => \Str::uuid()->toString(),
                    "type" => "App\\Notifications\\FlywheelNotification",
                    "notifiable_type" => "App\\Models\\User",
                    "notifiable_id" => $userId,
                    "data" => json_encode(array_merge(["type" => $type, "title" => $title, "body" => $body], $data)),
                    "read_at" => null,
                    "created_at" => now(),
                    "updated_at" => now(),
                ]);
            }

            // Send real FCM push
            if ($title && $body) {
                $tokens = FcmToken::getTokensForUser($userId);
                foreach ($tokens as $token) {
                    self::sendFcmPush($token, $title, $body, array_merge(["type" => $type], $data));
                }
            }

            Log::info("Notification sent to user {$userId}: type={$type}");
        } catch (\Throwable $e) {
            Log::warning("FcmNotificationService failed for user {$userId}: {$e->getMessage()}");
        }
    }

    /**
     * Send notification to multiple users.
     */
    public static function sendToUsers(array $userIds, string $type, array $data = [], ?string $title = null, ?string $body = null): void
    {
        foreach ($userIds as $userId) {
            self::sendToUser($userId, $type, $data, $title, $body);
        }
    }

    /**
     * Send FCM push via Firebase HTTP v1 API.
     */
    private static function sendFcmPush(string $fcmToken, string $title, string $body, array $data = []): bool
    {
        try {
            $accessToken = self::getAccessToken();
            if (!$accessToken) {
                Log::warning("FCM: Could not obtain access token");
                return false;
            }

            $projectId = self::getProjectId();
            $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

            // Stringify all data values (FCM requires string values)
            $stringData = [];
            foreach ($data as $k => $v) {
                $stringData[$k] = is_array($v) ? json_encode($v) : (string) $v;
            }

            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->post($url, [
                    "message" => [
                        "token" => $fcmToken,
                        "notification" => [
                            "title" => $title,
                            "body" => $body,
                        ],
                        "data" => $stringData,
                        "android" => [
                            "priority" => "high",
                            "notification" => [
                                "channel_id" => "tajiri_default",
                                "sound" => "default",
                            ],
                        ],
                        "apns" => [
                            "payload" => [
                                "aps" => [
                                    "sound" => "default",
                                    "badge" => 1,
                                ],
                            ],
                        ],
                    ],
                ]);

            if ($response->successful()) {
                return true;
            }

            // Handle invalid token - remove from DB
            if ($response->status() === 404 || $response->status() === 400) {
                $errorCode = $response->json("error.details.0.errorCode") ?? "";
                if (in_array($errorCode, ["UNREGISTERED", "INVALID_ARGUMENT"])) {
                    FcmToken::where("fcm_token", $fcmToken)->delete();
                    Log::info("FCM: Removed invalid token");
                }
            }

            Log::warning("FCM push failed: {$response->status()} {$response->body()}");
            return false;
        } catch (\Throwable $e) {
            Log::warning("FCM push exception: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Get OAuth2 access token for Firebase HTTP v1 API using service account JWT.
     */
    private static function getAccessToken(): ?string
    {
        // Check cache first
        $cached = Cache::get("fcm_access_token");
        if ($cached) {
            return $cached;
        }

        try {
            $saPath = storage_path("app/firebase/service-account.json");
            if (!file_exists($saPath)) {
                Log::error("FCM: Service account file not found");
                return null;
            }

            $sa = json_decode(file_get_contents($saPath), true);
            $now = time();
            $expiry = $now + 3500;

            $header = base64url_encode(json_encode(["alg" => "RS256", "typ" => "JWT"]));
            $claims = base64url_encode(json_encode([
                "iss" => $sa["client_email"],
                "scope" => "https://www.googleapis.com/auth/firebase.messaging",
                "aud" => "https://oauth2.googleapis.com/token",
                "iat" => $now,
                "exp" => $expiry,
            ]));

            $signature = "";
            $privateKey = openssl_pkey_get_private($sa["private_key"]);
            openssl_sign("{$header}.{$claims}", $signature, $privateKey, OPENSSL_ALGO_SHA256);
            $signature = base64url_encode($signature);

            $jwt = "{$header}.{$claims}.{$signature}";

            $response = Http::asForm()->post("https://oauth2.googleapis.com/token", [
                "grant_type" => "urn:ietf:params:oauth:grant-type:jwt-bearer",
                "assertion" => $jwt,
            ]);

            if ($response->successful()) {
                $token = $response->json("access_token");
                $expiresIn = $response->json("expires_in", 3600);
                Cache::put("fcm_access_token", $token, $expiresIn - 120);
                return $token;
            }

            Log::error("FCM token exchange failed: {$response->body()}");
            return null;
        } catch (\Throwable $e) {
            Log::error("FCM getAccessToken error: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Get Firebase project ID from service account.
     */
    private static function getProjectId(): string
    {
        $saPath = storage_path("app/firebase/service-account.json");
        $sa = json_decode(file_get_contents($saPath), true);
        return $sa["project_id"] ?? "tajiri-6d6ae";
    }
}

if (!function_exists("base64url_encode")) {
    function base64url_encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), "+/", "-_"), "=");
    }
}
