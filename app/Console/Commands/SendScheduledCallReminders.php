<?php

namespace App\Console\Commands;

use App\Models\FcmToken;
use App\Models\ScheduledCall;
use App\Models\UserProfile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendScheduledCallReminders extends Command
{
    protected $signature = 'calls:send-reminders';

    protected $description = 'Send push notification reminders for scheduled calls within 5 minutes';

    public function handle(): int
    {
        $upcomingCalls = ScheduledCall::whereNull('cancelled_at')
            ->whereNull('started_call_id')
            ->whereNull('reminder_sent_at')
            ->where('scheduled_at', '<=', now()->addMinutes(5))
            ->where('scheduled_at', '>', now())
            ->with(['creator:id,first_name,last_name', 'invitees'])
            ->get();

        $sentCount = 0;

        foreach ($upcomingCalls as $scheduledCall) {
            $creatorName = trim($scheduledCall->creator->first_name . ' ' . $scheduledCall->creator->last_name);
            $title = $scheduledCall->title ?? ($scheduledCall->type === 'video' ? 'Video Call' : 'Voice Call');
            $minutesUntil = (int) now()->diffInMinutes($scheduledCall->scheduled_at, false);
            $timeLabel = $minutesUntil <= 1 ? 'in 1 minute' : "in {$minutesUntil} minutes";

            // Notify all invitees
            foreach ($scheduledCall->invitees as $invitee) {
                if ($invitee->reminder_sent_at) {
                    continue;
                }

                $tokens = FcmToken::getTokensForUser($invitee->user_id);

                if (!empty($tokens)) {
                    $this->sendPushNotification($tokens, [
                        'title' => "Scheduled call {$timeLabel}",
                        'body' => "{$creatorName}: {$title}",
                    ], [
                        'type' => 'scheduled_call_reminder',
                        'scheduled_call_id' => (string) $scheduledCall->id,
                        'scheduled_at' => $scheduledCall->scheduled_at->toIso8601String(),
                        'call_type' => $scheduledCall->type,
                        'creator_name' => $creatorName,
                    ]);
                }

                $invitee->update(['reminder_sent_at' => now()]);
                $sentCount++;
            }

            // Also notify the creator
            $creatorTokens = FcmToken::getTokensForUser($scheduledCall->creator_id);
            if (!empty($creatorTokens)) {
                $this->sendPushNotification($creatorTokens, [
                    'title' => "Your scheduled call {$timeLabel}",
                    'body' => $title,
                ], [
                    'type' => 'scheduled_call_reminder',
                    'scheduled_call_id' => (string) $scheduledCall->id,
                    'scheduled_at' => $scheduledCall->scheduled_at->toIso8601String(),
                    'call_type' => $scheduledCall->type,
                ]);
            }

            $scheduledCall->update(['reminder_sent_at' => now()]);
        }

        if ($sentCount > 0) {
            $this->info("Sent {$sentCount} scheduled call reminders.");
        }

        return self::SUCCESS;
    }

    private function sendPushNotification(array $tokens, array $notification, array $data): void
    {
        try {
            $projectId = config('firebase.project_id');
            if (!$projectId) {
                return;
            }

            $credentialsPath = config('firebase.credentials');
            if (!$credentialsPath || !file_exists($credentialsPath)) {
                return;
            }

            $credentials = new \Google\Auth\Credentials\ServiceAccountCredentials(
                ['https://www.googleapis.com/auth/firebase.messaging'],
                $credentialsPath
            );
            $token = $credentials->fetchAuthToken();
            $accessToken = $token['access_token'] ?? null;

            if (!$accessToken) {
                return;
            }

            $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

            foreach ($tokens as $fcmToken) {
                Http::withToken($accessToken)
                    ->timeout(10)
                    ->post($url, [
                        'message' => [
                            'token' => $fcmToken,
                            'data' => $data,
                            'android' => [
                                'priority' => 'high',
                                'notification' => $notification,
                            ],
                            'apns' => [
                                'headers' => ['apns-priority' => '10'],
                                'payload' => [
                                    'aps' => [
                                        'alert' => $notification,
                                        'sound' => 'default',
                                    ],
                                ],
                            ],
                        ],
                    ]);
            }
        } catch (\Throwable $e) {
            Log::error('Scheduled call reminder push failed', ['error' => $e->getMessage()]);
        }
    }
}
