<?php

namespace App\Jobs;

use App\Models\FcmToken;
use App\Models\GroupCall;
use App\Models\UserProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendGroupCallNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $backoff = 2;

    public function __construct(
        public GroupCall $groupCall,
        public int $initiatorId,
        public array $recipientIds
    ) {}

    public function handle(): void
    {
        $initiator = UserProfile::find($this->initiatorId);

        if (!$initiator || empty($this->recipientIds)) {
            return;
        }

        $tokens = FcmToken::getTokensForUsers($this->recipientIds);

        if (empty($tokens)) {
            return;
        }

        $initiatorName = trim($initiator->first_name . ' ' . $initiator->last_name);
        $callType = $this->groupCall->type === 'video' ? 'video' : 'sauti';

        $notification = [
            'title' => $initiatorName,
            'body' => "Anakuita kwenye simu ya kikundi ya {$callType}",
        ];

        $initiatorAvatarUrl = $initiator->profile_photo_path
            ? asset('storage/' . $initiator->profile_photo_path)
            : '';

        $data = [
            'type' => 'call_incoming',
            'call_id' => $this->groupCall->call_id ?? '',
            'caller_id' => (string) $this->initiatorId,
            'caller_name' => $initiatorName,
            'caller_avatar_url' => $initiatorAvatarUrl,
            'call_type' => $this->groupCall->type,
            'ongoing' => 'true',
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
                Log::warning('FCM group call notification failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'group_call_id' => $this->groupCall->id,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('FCM group call notification error', [
                'error' => $e->getMessage(),
                'group_call_id' => $this->groupCall->id,
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
