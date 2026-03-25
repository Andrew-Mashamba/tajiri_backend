<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Events\CallIncoming;
use App\Jobs\RingTimeoutJob;
use App\Jobs\SendCallNotification;
use App\Models\BlockedUser;
use App\Models\Call;
use App\Models\FcmToken;
use App\Models\ScheduledCall;
use App\Models\ScheduledCallInvitee;
use App\Models\UserProfile;
use App\Services\TurnCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduledCallController extends Controller
{
    public function __construct(
        private TurnCredentialService $turnService
    ) {}

    /**
     * Get authenticated user ID from Bearer token or legacy user_id field.
     */
    private function authUserId(Request $request): int
    {
        if ($request->user()) {
            return (int) $request->user()->id;
        }
        return (int) ($request->input('user_id') ?? $request->query('user_id', 0));
    }

    /**
     * List scheduled calls for the authenticated user.
     * GET /api/scheduled-calls
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $scope = $request->query('scope', 'upcoming');

        $query = ScheduledCall::where(function ($q) use ($userId) {
                $q->where('creator_id', $userId)
                    ->orWhereHas('invitees', function ($q2) use ($userId) {
                        $q2->where('user_id', $userId);
                    });
            })
            ->whereNull('cancelled_at')
            ->with([
                'creator:id,first_name,last_name,username,profile_photo_path',
                'invitees.user:id,first_name,last_name,username,profile_photo_path',
                'startedCall',
            ]);

        if ($scope === 'upcoming') {
            $query->whereNull('started_call_id')
                ->where('scheduled_at', '>=', now())
                ->orderBy('scheduled_at');
        } else {
            $query->where(function ($q) {
                $q->where('scheduled_at', '<', now())
                    ->orWhereNotNull('started_call_id');
            })->orderByDesc('scheduled_at');
        }

        $scheduledCalls = $query->paginate($request->query('per_page', 20));

        $items = collect($scheduledCalls->items())->map(function ($sc) use ($userId) {
            $data = $sc->toArray();
            $data['is_creator'] = $sc->creator_id === $userId;
            return $data;
        });

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $scheduledCalls->currentPage(),
                'last_page' => $scheduledCalls->lastPage(),
                'per_page' => $scheduledCalls->perPage(),
                'total' => $scheduledCalls->total(),
            ],
        ]);
    }

    /**
     * Create a scheduled call.
     * POST /api/scheduled-calls
     */
    public function store(Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'type' => 'required|in:voice,video',
            'scheduled_at' => 'required|date|after:+5 minutes',
            'title' => 'nullable|string|max:255',
            'invitee_ids' => 'required|array|min:1|max:31',
            'invitee_ids.*' => 'integer|exists:user_profiles,id',
        ]);

        // Rate limit: 20 scheduled calls/user/day
        $todayCount = ScheduledCall::where('creator_id', $userId)
            ->whereDate('created_at', today())
            ->count();

        if ($todayCount >= 20) {
            return response()->json([
                'success' => false,
                'message' => 'Maximum 20 scheduled calls per day',
            ], 429);
        }

        // Filter out blocked users and self
        $inviteeIds = collect($validated['invitee_ids'])
            ->filter(fn($id) => (int) $id !== $userId && !BlockedUser::isEitherBlocked($userId, (int) $id))
            ->unique()
            ->values()
            ->toArray();

        if (empty($inviteeIds)) {
            return response()->json(['success' => false, 'message' => 'No valid invitees'], 422);
        }

        $scheduledCall = ScheduledCall::create([
            'creator_id' => $userId,
            'type' => $validated['type'],
            'scheduled_at' => $validated['scheduled_at'],
            'title' => $validated['title'] ?? null,
        ]);

        foreach ($inviteeIds as $inviteeId) {
            ScheduledCallInvitee::create([
                'scheduled_call_id' => $scheduledCall->id,
                'user_id' => $inviteeId,
                'notified_at' => now(),
            ]);
        }

        $this->sendInviteNotifications($scheduledCall, $userId, $inviteeIds);

        return response()->json([
            'success' => true,
            'data' => $scheduledCall->load([
                'creator:id,first_name,last_name,username,profile_photo_path',
                'invitees.user:id,first_name,last_name,username,profile_photo_path',
            ]),
        ], 201);
    }

    /**
     * Get a scheduled call.
     * GET /api/scheduled-calls/{id}
     */
    public function show(int $id, Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);

        $scheduledCall = ScheduledCall::with([
            'creator:id,first_name,last_name,username,profile_photo_path',
            'invitees.user:id,first_name,last_name,username,profile_photo_path',
            'startedCall',
        ])->findOrFail($id);

        $data = $scheduledCall->toArray();
        $data['is_creator'] = $scheduledCall->creator_id === $userId;

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Cancel a scheduled call.
     * DELETE /api/scheduled-calls/{id}
     */
    public function destroy(int $id, Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $scheduledCall = ScheduledCall::findOrFail($id);

        if ($userId !== $scheduledCall->creator_id) {
            return response()->json(['success' => false, 'message' => 'Only the creator can cancel'], 403);
        }

        if ($scheduledCall->isStarted()) {
            return response()->json(['success' => false, 'message' => 'Cannot cancel a started call'], 422);
        }

        $scheduledCall->update(['cancelled_at' => now()]);
        $this->sendCancellationNotifications($scheduledCall);

        return response()->json(['success' => true, 'message' => 'Scheduled call cancelled']);
    }

    /**
     * Start a scheduled call — creates real call session(s).
     * POST /api/scheduled-calls/{id}/start
     */
    public function start(int $id, Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $scheduledCall = ScheduledCall::with('invitees')->findOrFail($id);

        if ($userId !== $scheduledCall->creator_id) {
            return response()->json(['success' => false, 'message' => 'Only the creator can start'], 403);
        }

        if ($scheduledCall->isCancelled()) {
            return response()->json(['success' => false, 'message' => 'Cancelled'], 422);
        }

        if ($scheduledCall->isStarted()) {
            return response()->json([
                'success' => false,
                'message' => 'Already started',
                'data' => ['call_id' => $scheduledCall->started_call_id],
            ], 422);
        }

        $inviteeIds = $scheduledCall->invitees->pluck('user_id')->toArray();
        $iceServers = $this->turnService->generateCredentials($scheduledCall->creator_id);

        if (count($inviteeIds) === 1) {
            // 1:1 call
            $call = Call::create([
                'caller_id' => $scheduledCall->creator_id,
                'callee_id' => $inviteeIds[0],
                'type' => $scheduledCall->type,
                'status' => Call::STATUS_RINGING,
            ]);

            $scheduledCall->update(['started_call_id' => $call->id]);

            broadcast(CallIncoming::fromCall($call));
            SendCallNotification::dispatch($call, 'call_incoming');
            RingTimeoutJob::dispatch($call->id)->delay(now()->addSeconds(45));

            return response()->json([
                'success' => true,
                'data' => [
                    'call_id' => $call->call_id,
                    'status' => $call->status,
                    'type' => $call->type,
                    'ice_servers' => $iceServers['ice_servers'],
                    'created_at' => $call->created_at->toIso8601String(),
                ],
            ], 201);
        }

        // Multi-invitee: create individual 1:1 calls
        $calls = [];
        foreach ($inviteeIds as $inviteeId) {
            $call = Call::create([
                'caller_id' => $scheduledCall->creator_id,
                'callee_id' => $inviteeId,
                'type' => $scheduledCall->type,
                'status' => Call::STATUS_RINGING,
            ]);

            broadcast(CallIncoming::fromCall($call));
            SendCallNotification::dispatch($call, 'call_incoming');
            RingTimeoutJob::dispatch($call->id)->delay(now()->addSeconds(45));

            $calls[] = $call;
        }

        $scheduledCall->update(['started_call_id' => $calls[0]->id]);

        return response()->json([
            'success' => true,
            'data' => [
                'call_id' => $calls[0]->call_id,
                'status' => $calls[0]->status,
                'type' => $calls[0]->type,
                'ice_servers' => $iceServers['ice_servers'],
                'created_at' => $calls[0]->created_at->toIso8601String(),
            ],
        ], 201);
    }

    // ==================== FCM Helpers ====================

    private function sendInviteNotifications(ScheduledCall $scheduledCall, int $creatorId, array $inviteeIds): void
    {
        $creator = UserProfile::find($creatorId);
        if (!$creator) return;

        $creatorName = trim($creator->first_name . ' ' . $creator->last_name);
        $callType = $scheduledCall->type === 'video' ? 'video' : 'voice';
        $scheduledAt = $scheduledCall->scheduled_at->format('M j \a\t H:i');

        foreach ($inviteeIds as $inviteeId) {
            $tokens = FcmToken::getTokensForUser($inviteeId);
            if (empty($tokens)) continue;

            $notification = [
                'title' => 'Scheduled call invitation',
                'body' => "{$creatorName} scheduled a {$callType} call on {$scheduledAt}",
            ];

            $data = [
                'type' => 'scheduled_call_invite',
                'scheduled_call_id' => (string) $scheduledCall->id,
                'scheduled_at' => $scheduledCall->scheduled_at->toIso8601String(),
                'call_type' => $scheduledCall->type,
                'creator_name' => $creatorName,
                'title' => $scheduledCall->title ?? '',
            ];

            foreach ($tokens as $token) {
                $this->sendFcmNotification($token, $notification, $data);
            }
        }
    }

    private function sendCancellationNotifications(ScheduledCall $scheduledCall): void
    {
        $creator = UserProfile::find($scheduledCall->creator_id);
        if (!$creator) return;

        $creatorName = trim($creator->first_name . ' ' . $creator->last_name);

        foreach ($scheduledCall->invitees as $invitee) {
            $tokens = FcmToken::getTokensForUser($invitee->user_id);
            if (empty($tokens)) continue;

            $notification = [
                'title' => 'Scheduled call cancelled',
                'body' => "{$creatorName} cancelled the scheduled call",
            ];

            $data = [
                'type' => 'scheduled_call_cancelled',
                'scheduled_call_id' => (string) $scheduledCall->id,
            ];

            foreach ($tokens as $token) {
                $this->sendFcmNotification($token, $notification, $data);
            }
        }
    }

    private function sendFcmNotification(string $token, array $notification, array $data): void
    {
        try {
            $projectId = config('firebase.project_id');
            if (!$projectId) return;

            $credentialsPath = config('firebase.credentials');
            if (!$credentialsPath || !file_exists($credentialsPath)) return;

            $credentials = new \Google\Auth\Credentials\ServiceAccountCredentials(
                ['https://www.googleapis.com/auth/firebase.messaging'],
                $credentialsPath
            );
            $accessToken = ($credentials->fetchAuthToken())['access_token'] ?? null;
            if (!$accessToken) return;

            \Illuminate\Support\Facades\Http::withToken($accessToken)
                ->timeout(10)
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                    'message' => [
                        'token' => $token,
                        'data' => $data,
                        'android' => [
                            'priority' => 'normal',
                            'notification' => $notification,
                        ],
                        'apns' => [
                            'payload' => [
                                'aps' => [
                                    'alert' => $notification,
                                    'sound' => 'default',
                                ],
                            ],
                        ],
                    ],
                ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Scheduled call notification failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
