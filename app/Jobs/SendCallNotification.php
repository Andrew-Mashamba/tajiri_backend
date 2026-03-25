<?php

namespace App\Jobs;

use App\Models\Call;
use App\Models\FcmToken;
use App\Models\UserProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendCallNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $backoff = 2;

    public function __construct(
        public Call $call,
        public string $event = 'incoming_call'
    ) {}

    public function handle(): void
    {
        $call = $this->call;
        $caller = UserProfile::find($call->caller_id);

        if (!$caller) {
            return;
        }

        $recipientId = $this->event === 'call_incoming' ? $call->callee_id : $call->caller_id;
        $tokens = FcmToken::getTokensForUser($recipientId);

        if (empty($tokens)) {
            return;
        }

        $callerName = trim($caller->first_name . ' ' . $caller->last_name);

        $notification = match ($this->event) {
            'call_incoming' => [
                'title' => $callerName,
                'body' => $call->type === 'video' ? 'Simu ya video inakuja...' : 'Simu ya sauti inakuja...',
            ],
            'call_missed' => [
                'title' => $callerName,
                'body' => 'Simu ambayo hukuipokea',
            ],
            default => [
                'title' => $callerName,
                'body' => 'Simu',
            ],
        };

        $callerAvatarUrl = $caller->profile_photo_path
            ? asset('storage/' . $caller->profile_photo_path)
            : '';

        // FCM type: "call_incoming" for incoming calls (Flutter routes on this exact string)
        $fcmType = $this->event === 'call_incoming' ? 'call_incoming' : $this->event;

        $data = [
            'type' => $fcmType,
            'call_id' => $call->call_id,              // UUID for Flutter
            'caller_id' => (string) $call->caller_id,
            'caller_name' => $callerName,
            'caller_avatar_url' => $callerAvatarUrl,
            'call_type' => $call->type,
            'ongoing' => 'false',
        ];

        foreach ($tokens as $token) {
            $this->sendFcmNotification($token, $notification, $data);
        }
    }

    private function sendFcmNotification(string $token, array $notification, array $data): void
    {
        try {
            $projectId = config('firebase.project_id');
            if (!$projectId) {
                return;
            }

            $accessToken = $this->getAccessToken();
            if (!$accessToken) {
                return;
            }

            $payload = [
                'message' => [
                    'token' => $token,
                    'data' => $data,
                    'android' => [
                        'priority' => 'high',
                        'notification' => array_merge($notification, [
                            'channel_id' => 'incoming_calls',
                            'sound' => 'ringtone',
                        ]),
                    ],
                    'apns' => [
                        'headers' => [
                            'apns-priority' => '10',
                            'apns-push-type' => 'voip',
                        ],
                        'payload' => [
                            'aps' => [
                                'alert' => $notification,
                                'sound' => 'ringtone.caf',
                                'badge' => 1,
                                'content-available' => 1,
                            ],
                        ],
                    ],
                ],
            ];

            $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

            $response = \Illuminate\Support\Facades\Http::withToken($accessToken)
                ->timeout(10)
                ->post($url, $payload);

            if (!$response->successful()) {
                Log::warning('FCM call notification failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'call_id' => $this->call->id,
                    'event' => $this->event,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('FCM call notification error', [
                'error' => $e->getMessage(),
                'call_id' => $this->call->id,
            ]);
        }
    }

    private function getAccessToken(): ?string
    {
        try {
            $credentialsPath = config('firebase.credentials');

            if (!$credentialsPath || !file_exists($credentialsPath)) {
                return null;
            }

            $credentials = new \Google\Auth\Credentials\ServiceAccountCredentials(
                ['https://www.googleapis.com/auth/firebase.messaging'],
                $credentialsPath
            );

            $token = $credentials->fetchAuthToken();

            return $token['access_token'] ?? null;
        } catch (\Throwable $e) {
            Log::error('FCM auth token fetch failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
