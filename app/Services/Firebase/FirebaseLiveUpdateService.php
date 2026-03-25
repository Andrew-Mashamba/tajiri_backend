<?php

namespace App\Services\Firebase;

use App\Models\UserProfile;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FirebaseLiveUpdateService
{
    protected ?string $accessToken = null;
    protected ?int $tokenExpiresAt = null;
    protected string $collection;
    protected string $projectId;
    protected bool $enabled;

    public function __construct()
    {
        $this->enabled = config('firebase.live_updates.enabled', true);
        $this->collection = config('firebase.live_updates.collection', 'updates');
        $this->projectId = config('firebase.project_id', '');
    }

    /**
     * Get a valid access token for Firestore REST API.
     */
    protected function getAccessToken(): ?string
    {
        if ($this->accessToken && $this->tokenExpiresAt && time() < $this->tokenExpiresAt - 60) {
            return $this->accessToken;
        }

        try {
            $credentialsPath = config('firebase.credentials');

            if (!$credentialsPath || !file_exists($credentialsPath)) {
                Log::warning('Firebase credentials file not found', ['path' => $credentialsPath]);
                return null;
            }

            $credentials = new ServiceAccountCredentials(
                ['https://www.googleapis.com/auth/datastore'],
                $credentialsPath
            );

            $token = $credentials->fetchAuthToken();

            $this->accessToken = $token['access_token'] ?? null;
            $this->tokenExpiresAt = time() + ($token['expires_in'] ?? 3600);

            return $this->accessToken;
        } catch (\Throwable $e) {
            Log::error('Firebase auth token fetch failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Build the Firestore REST API URL for a document.
     */
    protected function getDocumentUrl(string $documentId): string
    {
        return sprintf(
            'https://firestore.googleapis.com/v1/projects/%s/databases/(default)/documents/%s/%s',
            $this->projectId,
            $this->collection,
            $documentId
        );
    }

    /**
     * Convert a PHP value to Firestore Value format.
     */
    protected function toFirestoreValue(mixed $value): array
    {
        if (is_null($value)) {
            return ['nullValue' => null];
        }
        if (is_bool($value)) {
            return ['booleanValue' => $value];
        }
        if (is_int($value)) {
            return ['integerValue' => (string) $value];
        }
        if (is_float($value)) {
            return ['doubleValue' => $value];
        }
        if (is_string($value)) {
            return ['stringValue' => $value];
        }
        if (is_array($value)) {
            // Check if it's an associative array (map) or sequential (array)
            if (array_is_list($value)) {
                return [
                    'arrayValue' => [
                        'values' => array_map(fn($v) => $this->toFirestoreValue($v), $value),
                    ],
                ];
            }
            $fields = [];
            foreach ($value as $k => $v) {
                $fields[$k] = $this->toFirestoreValue($v);
            }
            return ['mapValue' => ['fields' => $fields]];
        }

        return ['stringValue' => (string) $value];
    }

    /**
     * Notify a single user by writing to Firestore updates/{userId}.
     */
    public function notifyUser(int $userId, string $event, array $payload = []): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $token = $this->getAccessToken();
        if (!$token) {
            return false;
        }

        try {
            $fields = [
                'ts' => $this->toFirestoreValue(now()->getTimestampMs()),
                'event' => $this->toFirestoreValue($event),
            ];

            if (!empty($payload)) {
                $fields['payload'] = $this->toFirestoreValue($payload);
            }

            $url = $this->getDocumentUrl((string) $userId);

            // Use PATCH with updateMask to merge fields
            $updateMask = implode('&', array_map(
                fn($f) => 'updateMask.fieldPaths=' . $f,
                array_keys($fields)
            ));

            $response = Http::withToken($token)
                ->timeout(5)
                ->patch($url . '?' . $updateMask, [
                    'fields' => $fields,
                ]);

            if ($response->successful()) {
                return true;
            }

            Log::error('Firebase live-update HTTP error', [
                'userId' => $userId,
                'event' => $event,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return false;
        } catch (\Throwable $e) {
            Log::error('Firebase live-update failed', [
                'userId' => $userId,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Notify multiple users with the same event.
     */
    public function notifyUsers(array $userIds, string $event, array $payload = []): void
    {
        if (!$this->enabled || empty($userIds)) {
            return;
        }

        foreach ($userIds as $userId) {
            $this->notifyUser((int) $userId, $event, $payload);
        }
    }

    /**
     * Notify all friends/followers of a user.
     */
    public function notifyFriends(int $userId, string $event, array $payload = []): void
    {
        try {
            $user = UserProfile::find($userId);
            if (!$user) {
                return;
            }

            $friendIds = $user->getFriendIds();
            $this->notifyUsers($friendIds, $event, $payload);
        } catch (\Throwable $e) {
            Log::error('Firebase notifyFriends failed', [
                'userId' => $userId,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify a user's friends AND the user themselves.
     */
    public function notifyUserAndFriends(int $userId, string $event, array $payload = []): void
    {
        $this->notifyUser($userId, $event, $payload);
        $this->notifyFriends($userId, $event, $payload);
    }

    /**
     * Notify all participants of a conversation except a specific user.
     */
    public function notifyConversationParticipants(int $conversationId, int $exceptUserId, string $event, array $payload = []): void
    {
        try {
            $participantIds = \DB::table('conversation_participants')
                ->where('conversation_id', $conversationId)
                ->where('user_id', '!=', $exceptUserId)
                ->pluck('user_id')
                ->toArray();

            $payload['conversation_id'] = $conversationId;
            $this->notifyUsers($participantIds, $event, $payload);
        } catch (\Throwable $e) {
            Log::error('Firebase notifyConversationParticipants failed', [
                'conversationId' => $conversationId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
