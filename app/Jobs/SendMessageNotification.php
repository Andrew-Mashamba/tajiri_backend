<?php

namespace App\Jobs;

use App\Models\ConversationParticipant;
use App\Models\FcmToken;
use App\Models\Message;
use App\Models\UserProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendMessageNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public function __construct(
        public Message $message
    ) {}

    public function handle(): void
    {
        $message = $this->message;
        $sender = UserProfile::find($message->sender_id);

        if (!$sender) {
            return;
        }

        // Get all other participants who have NOT muted this conversation
        $recipientIds = ConversationParticipant::where('conversation_id', $message->conversation_id)
            ->where('user_id', '!=', $message->sender_id)
            ->where('is_muted', false)
            ->pluck('user_id')
            ->toArray();

        if (empty($recipientIds)) {
            return;
        }

        $tokens = FcmToken::getTokensForUsers($recipientIds);

        if (empty($tokens)) {
            return;
        }

        $senderName = trim($sender->first_name . ' ' . $sender->last_name);
        $body = $this->getNotificationBody($message);

        foreach ($tokens as $token) {
            $this->sendFcmNotification($token, [
                'title' => $senderName,
                'body' => $body,
            ], [
                'type' => 'new_message',
                'conversation_id' => (string) $message->conversation_id,
                'message_id' => (string) $message->id,
                'sender_id' => (string) $message->sender_id,
                'message_type' => $message->message_type,
            ]);
        }
    }

    private function getNotificationBody(Message $message): string
    {
        return match ($message->message_type) {
            'image' => 'Amekutumia picha',
            'video' => 'Amekutumia video',
            'audio' => 'Amekutumia sauti',
            'document' => 'Amekutumia faili',
            'location' => 'Amekutumia mahali',
            'contact' => 'Amekutumia anwani',
            default => mb_substr($message->content ?? '', 0, 100),
        };
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
                    'notification' => $notification,
                    'data' => $data,
                    'android' => [
                        'priority' => 'high',
                        'notification' => [
                            'channel_id' => 'messages',
                            'sound' => 'default',
                        ],
                    ],
                    'apns' => [
                        'payload' => [
                            'aps' => [
                                'sound' => 'default',
                                'badge' => 1,
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
                Log::warning('FCM message notification failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'message_id' => $this->message->id,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('FCM message notification error', [
                'error' => $e->getMessage(),
                'message_id' => $this->message->id,
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
