<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Events\CallAccepted;
use App\Events\CallEnded;
use App\Events\CallIncoming;
use App\Events\CallReaction;
use App\Events\CallRejected;
use App\Events\ParticipantAdded;
use App\Events\ParticipantLeft;
use App\Events\ParticipantRemoved;
use App\Events\RaiseHand;
use App\Events\SignalingAnswer;
use App\Events\SignalingIceCandidate;
use App\Events\SignalingOffer;
use App\Jobs\RingTimeoutJob;
use App\Jobs\SendCallNotification;
use App\Jobs\SendGroupCallNotification;
use App\Models\BlockedUser;
use App\Models\Call;
use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Group;
use App\Models\GroupCall;
use App\Models\GroupCallParticipant;
use App\Models\Message;
use App\Models\UserProfile;
use App\Services\TurnCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CallController extends Controller
{
    public function __construct(
        private TurnCredentialService $turnService
    ) {}

    // ==================== Helpers ====================

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
     * Resolve a call (1:1 or group) by numeric ID or UUID string.
     * Returns ['type' => '1to1'|'group', 'model' => Call|GroupCall, 'uuid' => string].
     */
    private function resolveCall(string $id): ?array
    {
        if (is_numeric($id)) {
            $call = Call::find((int) $id);
            if ($call) {
                return ['type' => '1to1', 'model' => $call, 'uuid' => $call->call_id];
            }
            $groupCall = GroupCall::find((int) $id);
            if ($groupCall) {
                return ['type' => 'group', 'model' => $groupCall, 'uuid' => $groupCall->call_id];
            }
        }

        $call = Call::where('call_id', $id)->first();
        if ($call) {
            return ['type' => '1to1', 'model' => $call, 'uuid' => $call->call_id];
        }

        $groupCall = GroupCall::where('call_id', $id)->first();
        if ($groupCall) {
            return ['type' => 'group', 'model' => $groupCall, 'uuid' => $groupCall->call_id];
        }

        return null;
    }

    /**
     * Get user display name and avatar URL.
     */
    private function userInfo(int $userId): array
    {
        $user = UserProfile::find($userId);
        $name = $user ? trim($user->first_name . ' ' . $user->last_name) : '';
        $avatar = $user?->profile_photo_path
            ? asset('storage/' . $user->profile_photo_path)
            : '';
        return ['name' => $name, 'avatar_url' => $avatar];
    }

    // ==================== Create Call ====================

    /**
     * Create a call (1:1 or group).
     * POST /api/calls
     *
     * 1:1: { "callee_id": int, "type": "voice"|"video" }
     * Group: { "group_id": int, "invited_user_ids": [int], "type": "voice"|"video" }
     */
    public function create(Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $type = $request->input('type');
        if (!in_array($type, ['voice', 'video'])) {
            return response()->json(['success' => false, 'message' => 'type must be voice or video'], 422);
        }

        // Determine call mode: 1:1 vs group
        if ($request->has('callee_id') && !$request->has('group_id')) {
            return $this->create1to1($request, $userId, $type);
        }

        if ($request->has('group_id') || $request->has('invited_user_ids')) {
            return $this->createGroup($request, $userId, $type);
        }

        return response()->json([
            'success' => false,
            'message' => 'Provide callee_id for 1:1 or group_id/invited_user_ids for group call',
        ], 422);
    }

    private function create1to1(Request $request, int $callerId, string $type): JsonResponse
    {
        $validated = $request->validate([
            'callee_id' => 'required|integer|exists:user_profiles,id',
        ]);

        $calleeId = (int) $validated['callee_id'];

        if ($callerId === $calleeId) {
            return response()->json(['success' => false, 'message' => 'Cannot call yourself'], 400);
        }

        if (BlockedUser::isEitherBlocked($callerId, $calleeId)) {
            return response()->json(['success' => false, 'message' => 'Cannot call this user'], 403);
        }

        // Check if callee is already in a call
        $existingCall = Call::where('callee_id', $calleeId)
            ->whereIn('status', ['pending', 'ringing', 'answered'])
            ->first();

        if ($existingCall) {
            return response()->json([
                'success' => false,
                'message' => 'User is on another call',
                'status' => 'busy',
            ], 400);
        }

        $call = Call::create([
            'caller_id' => $callerId,
            'callee_id' => $calleeId,
            'type' => $type,
            'status' => Call::STATUS_RINGING,
        ]);

        // Broadcast via WebSocket
        broadcast(CallIncoming::fromCall($call));

        // Send FCM push to callee
        SendCallNotification::dispatch($call, 'call_incoming');

        // Ring timeout
        RingTimeoutJob::dispatch($call->id)->delay(now()->addSeconds(45));

        $iceServers = $this->turnService->generateCredentials($callerId);

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

    private function createGroup(Request $request, int $initiatorId, string $type): JsonResponse
    {
        $validated = $request->validate([
            'group_id' => 'sometimes|integer|exists:groups,id',
            'invited_user_ids' => 'sometimes|array',
            'invited_user_ids.*' => 'integer|exists:user_profiles,id',
            'conversation_id' => 'sometimes|integer|exists:conversations,id',
        ]);

        // Resolve conversation: from group or direct conversation_id
        $conversation = null;
        if (isset($validated['group_id'])) {
            $group = Group::findOrFail($validated['group_id']);
            $conversation = $group->getOrCreateConversation();
        } elseif (isset($validated['conversation_id'])) {
            $conversation = Conversation::findOrFail($validated['conversation_id']);
        }

        if (!$conversation) {
            return response()->json([
                'success' => false,
                'message' => 'group_id or conversation_id required for group call',
            ], 422);
        }

        // Check for existing active group call
        $existingCall = GroupCall::where('conversation_id', $conversation->id)
            ->where('status', 'active')
            ->first();

        if ($existingCall) {
            return response()->json([
                'success' => true,
                'message' => 'Group call already active',
                'data' => [
                    'call_id' => $existingCall->call_id,
                    'status' => $existingCall->status,
                    'type' => $existingCall->type,
                    'created_at' => $existingCall->created_at->toIso8601String(),
                ],
            ]);
        }

        $groupCall = GroupCall::create([
            'conversation_id' => $conversation->id,
            'initiated_by' => $initiatorId,
            'type' => $type,
        ]);

        // Add initiator as first participant
        $participant = $groupCall->addParticipant($initiatorId);
        $participant->update(['role' => 'caller']);

        // Determine who to invite
        $invitedUserIds = [];
        if (!empty($validated['invited_user_ids'])) {
            foreach ($validated['invited_user_ids'] as $uid) {
                if ((int) $uid !== $initiatorId && $conversation->hasParticipant($uid)) {
                    GroupCallParticipant::create([
                        'group_call_id' => $groupCall->id,
                        'user_id' => $uid,
                        'status' => 'invited',
                        'role' => 'callee',
                        'invited_at' => now(),
                    ]);
                    $invitedUserIds[] = (int) $uid;
                }
            }
        } else {
            foreach ($conversation->participants as $p) {
                if ((int) $p->id !== $initiatorId) {
                    GroupCallParticipant::create([
                        'group_call_id' => $groupCall->id,
                        'user_id' => $p->id,
                        'status' => 'invited',
                        'role' => 'callee',
                        'invited_at' => now(),
                    ]);
                    $invitedUserIds[] = (int) $p->id;
                }
            }
        }

        // Send FCM to invited users
        if (!empty($invitedUserIds)) {
            SendGroupCallNotification::dispatch($groupCall, $initiatorId, $invitedUserIds);
        }

        // Broadcast CallIncoming to each invitee (ongoing=true for group-add)
        $initiatorInfo = $this->userInfo($initiatorId);
        foreach ($invitedUserIds as $inviteeId) {
            broadcast(new CallIncoming(
                $groupCall->call_id,
                $initiatorId,
                $initiatorInfo['name'],
                $initiatorInfo['avatar_url'],
                $type,
                $inviteeId,
                true
            ));
        }

        $iceServers = $this->turnService->generateCredentials($initiatorId);

        return response()->json([
            'success' => true,
            'data' => [
                'call_id' => $groupCall->call_id,
                'status' => $groupCall->status,
                'type' => $groupCall->type,
                'ice_servers' => $iceServers['ice_servers'],
                'created_at' => $groupCall->created_at->toIso8601String(),
            ],
        ], 201);
    }

    // ==================== Accept / Reject / End ====================

    /**
     * Accept a call.
     * POST /api/calls/{id}/accept
     */
    public function accept(string $id, Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $resolved = $this->resolveCall($id);

        if (!$resolved) {
            return response()->json(['success' => false, 'message' => 'Call not found'], 404);
        }

        if ($resolved['type'] === '1to1') {
            $call = $resolved['model'];

            if ($call->status !== Call::STATUS_RINGING) {
                return response()->json(['success' => false, 'message' => 'Call is not ringing'], 422);
            }

            $call->answer();
            $freshCall = $call->fresh();

            $sdpAnswer = $request->input('sdp_answer');

            broadcast(new CallAccepted(
                $freshCall->call_id,
                $userId,
                is_array($sdpAnswer) ? $sdpAnswer : null
            ))->toOthers();

            // If client sent SDP answer, also forward via signaling
            if (is_array($sdpAnswer)) {
                broadcast(new SignalingAnswer(
                    $freshCall->call_id,
                    $userId,
                    $sdpAnswer
                ))->toOthers();
            }

            $iceServers = $this->turnService->generateCredentials($userId);

            return response()->json([
                'success' => true,
                'data' => [
                    'call_id' => $freshCall->call_id,
                    'status' => $freshCall->status,
                    'ice_servers' => $iceServers['ice_servers'],
                    'started_at' => $freshCall->answered_at?->toIso8601String(),
                ],
            ]);
        }

        // Group call: join
        $groupCall = $resolved['model'];
        if (!$groupCall->isActive()) {
            return response()->json(['success' => false, 'message' => 'Call has ended'], 400);
        }

        $groupCall->addParticipant($userId);

        $info = $this->userInfo($userId);
        broadcast(new ParticipantAdded(
            $groupCall->call_id,
            $userId,
            $info['name'],
            $info['avatar_url'],
            $userId
        ));

        $iceServers = $this->turnService->generateCredentials($userId);

        return response()->json([
            'success' => true,
            'data' => [
                'call_id' => $groupCall->call_id,
                'status' => $groupCall->status,
                'ice_servers' => $iceServers['ice_servers'],
                'started_at' => $groupCall->started_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Reject a call.
     * POST /api/calls/{id}/reject
     */
    public function reject(string $id, Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $resolved = $this->resolveCall($id);

        if (!$resolved) {
            return response()->json(['success' => false, 'message' => 'Call not found'], 404);
        }

        if ($resolved['type'] === '1to1') {
            $call = $resolved['model'];

            if (!in_array($call->status, [Call::STATUS_PENDING, Call::STATUS_RINGING])) {
                return response()->json(['success' => false, 'message' => 'Call cannot be rejected'], 422);
            }

            $call->decline();

            broadcast(new CallRejected($call->call_id, $userId))->toOthers();

            return response()->json([
                'success' => true,
                'data' => [
                    'call_id' => $call->call_id,
                    'status' => $call->fresh()->status,
                ],
            ]);
        }

        // Group call: decline invitation
        $groupCall = $resolved['model'];
        GroupCallParticipant::where('group_call_id', $groupCall->id)
            ->where('user_id', $userId)
            ->update(['status' => 'declined']);

        return response()->json([
            'success' => true,
            'data' => [
                'call_id' => $groupCall->call_id,
                'status' => 'declined',
            ],
        ]);
    }

    /**
     * End a call.
     * POST /api/calls/{id}/end
     */
    public function end(string $id, Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $resolved = $this->resolveCall($id);

        if (!$resolved) {
            return response()->json(['success' => false, 'message' => 'Call not found'], 404);
        }

        if ($resolved['type'] === '1to1') {
            $call = $resolved['model'];

            if (!$call->isActive()) {
                return response()->json(['success' => false, 'message' => 'Call already ended'], 400);
            }

            $reason = $request->input('reason', Call::END_COMPLETED);
            $call->end($reason);
            $freshCall = $call->fresh();

            broadcast(new CallEnded($freshCall->call_id, $userId, 'ended_by_user'))->toOthers();

            return response()->json([
                'success' => true,
                'data' => [
                    'call_id' => $freshCall->call_id,
                    'status' => $freshCall->status,
                    'ended_at' => $freshCall->ended_at?->toIso8601String(),
                    'duration_seconds' => $freshCall->duration,
                ],
            ]);
        }

        // Group call
        $groupCall = $resolved['model'];
        $groupCall->end();

        broadcast(new CallEnded($groupCall->call_id, $userId, 'ended_by_user'));

        return response()->json([
            'success' => true,
            'data' => [
                'call_id' => $groupCall->call_id,
                'status' => 'ended',
                'ended_at' => $groupCall->fresh()->ended_at?->toIso8601String(),
            ],
        ]);
    }

    // ==================== Signaling ====================

    /**
     * Relay SDP/ICE signaling data.
     * POST /api/calls/{id}/signaling
     */
    public function signaling(string $id, Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $resolved = $this->resolveCall($id);

        if (!$resolved) {
            return response()->json(['success' => false, 'message' => 'Call not found'], 404);
        }

        $validated = $request->validate([
            'type' => 'required|in:offer,answer,ice_candidate',
            'sdp' => 'required_unless:type,ice_candidate|array',
            'candidate' => 'required_if:type,ice_candidate|array',
        ]);

        $uuid = $resolved['uuid'];

        match ($validated['type']) {
            'offer' => broadcast(new SignalingOffer($uuid, $userId, $validated['sdp']))->toOthers(),
            'answer' => broadcast(new SignalingAnswer($uuid, $userId, $validated['sdp']))->toOthers(),
            'ice_candidate' => broadcast(new SignalingIceCandidate($uuid, $userId, $validated['candidate']))->toOthers(),
        };

        return response()->json(['success' => true], 202);
    }

    // ==================== TURN Credentials ====================

    /**
     * Get TURN/STUN credentials.
     * GET /api/calls/turn-credentials
     */
    public function turnCredentials(Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);

        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $credentials = $this->turnService->generateCredentials($userId);

        return response()->json([
            'success' => true,
            'data' => $credentials,
        ]);
    }

    // ==================== Call Log ====================

    /**
     * Get call history for authenticated user.
     * GET /api/calls
     */
    public function callLog(Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);

        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $query = CallLog::where('user_id', $userId)
            ->with('otherUser:id,first_name,last_name,username,profile_photo_path')
            ->orderByDesc('call_time');

        if ($request->query('type')) {
            $query->where('type', $request->query('type'));
        }
        if ($request->query('direction')) {
            $query->where('direction', $request->query('direction'));
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $logs = $query->paginate($perPage);

        $data = collect($logs->items())->map(function ($log) {
            $otherParty = $log->otherUser;
            return [
                'call_id' => $log->call ? $log->call->call_id : null,
                'type' => $log->type,
                'status' => $log->status,
                'direction' => $log->direction,
                'other_party' => $otherParty ? [
                    'id' => $otherParty->id,
                    'first_name' => $otherParty->first_name,
                    'last_name' => $otherParty->last_name,
                    'profile_photo_path' => $otherParty->profile_photo_path,
                    'avatar_url' => $otherParty->profile_photo_path
                        ? asset('storage/' . $otherParty->profile_photo_path)
                        : '',
                ] : null,
                'started_at' => $log->call_time?->toIso8601String(),
                'ended_at' => null,
                'duration_seconds' => $log->duration,
                'created_at' => $log->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * Get a single call's details.
     * GET /api/calls/{id}
     */
    public function show(string $id): JsonResponse
    {
        $resolved = $this->resolveCall($id);

        if (!$resolved) {
            return response()->json(['success' => false, 'message' => 'Call not found'], 404);
        }

        if ($resolved['type'] === '1to1') {
            $call = $resolved['model']->load(['caller', 'callee']);
            return response()->json([
                'success' => true,
                'data' => [
                    'call_id' => $call->call_id,
                    'type' => $call->type,
                    'status' => $call->status,
                    'caller_id' => $call->caller_id,
                    'callee_id' => $call->callee_id,
                    'caller' => $call->caller,
                    'callee' => $call->callee,
                    'started_at' => $call->answered_at?->toIso8601String(),
                    'ended_at' => $call->ended_at?->toIso8601String(),
                    'duration_seconds' => $call->duration,
                    'created_at' => $call->created_at->toIso8601String(),
                ],
            ]);
        }

        $groupCall = $resolved['model']->load('participants.user');
        return response()->json([
            'success' => true,
            'data' => [
                'call_id' => $groupCall->call_id,
                'type' => $groupCall->type,
                'status' => $groupCall->status,
                'initiated_by' => $groupCall->initiated_by,
                'conversation_id' => $groupCall->conversation_id,
                'participants' => $groupCall->participants->map(fn($p) => [
                    'user_id' => $p->user_id,
                    'user_name' => $p->user ? trim($p->user->first_name . ' ' . $p->user->last_name) : '',
                    'status' => $p->status,
                    'role' => $p->role,
                    'joined_at' => $p->joined_at?->toIso8601String(),
                    'left_at' => $p->left_at?->toIso8601String(),
                ]),
                'started_at' => $groupCall->started_at?->toIso8601String(),
                'ended_at' => $groupCall->ended_at?->toIso8601String(),
                'created_at' => $groupCall->created_at->toIso8601String(),
            ],
        ]);
    }

    // ==================== Participants ====================

    /**
     * Add a participant mid-call.
     * POST /api/calls/{id}/participants
     */
    public function addParticipant(string $id, Request $request): JsonResponse
    {
        $authUserId = $this->authUserId($request);
        $resolved = $this->resolveCall($id);

        if (!$resolved) {
            return response()->json(['success' => false, 'message' => 'Call not found'], 404);
        }

        $validated = $request->validate([
            'user_id' => 'required|integer|exists:user_profiles,id',
        ]);

        $targetUserId = (int) $validated['user_id'];

        if ($resolved['type'] === 'group') {
            $groupCall = $resolved['model'];

            if (!$groupCall->isActive()) {
                return response()->json(['success' => false, 'message' => 'Call is not active'], 400);
            }

            // Verify adder is joined
            $adderIsJoined = GroupCallParticipant::where('group_call_id', $groupCall->id)
                ->where('user_id', $authUserId)
                ->where('status', 'joined')
                ->exists();

            if (!$adderIsJoined) {
                return response()->json(['success' => false, 'message' => 'Only active participants can add others'], 403);
            }

            // Check 32 cap
            $activeCount = GroupCallParticipant::where('group_call_id', $groupCall->id)
                ->whereIn('status', ['joined', 'invited', 'ringing'])
                ->count();

            if ($activeCount >= 32) {
                return response()->json(['success' => false, 'message' => 'Maximum participants reached (32)'], 422);
            }

            // Check if already in call
            $existing = GroupCallParticipant::where('group_call_id', $groupCall->id)
                ->where('user_id', $targetUserId)
                ->first();

            if ($existing && in_array($existing->status, ['joined', 'invited', 'ringing'])) {
                return response()->json(['success' => true, 'message' => 'User already in call']);
            }

            if ($existing) {
                $existing->update(['status' => 'invited', 'role' => 'callee', 'invited_at' => now()]);
            } else {
                GroupCallParticipant::create([
                    'group_call_id' => $groupCall->id,
                    'user_id' => $targetUserId,
                    'status' => 'invited',
                    'role' => 'callee',
                    'invited_at' => now(),
                ]);
            }

            $info = $this->userInfo($targetUserId);
            broadcast(new ParticipantAdded(
                $groupCall->call_id,
                $targetUserId,
                $info['name'],
                $info['avatar_url'],
                $authUserId
            ));

            // Send FCM + CallIncoming to the added user
            SendGroupCallNotification::dispatch($groupCall, $authUserId, [$targetUserId]);

            $adderInfo = $this->userInfo($authUserId);
            broadcast(new CallIncoming(
                $groupCall->call_id,
                $authUserId,
                $adderInfo['name'],
                $adderInfo['avatar_url'],
                $groupCall->type,
                $targetUserId,
                true // ongoing group-add
            ));

            return response()->json(['success' => true, 'message' => 'User invited'], 201);
        }

        // 1:1 calls don't support adding participants
        return response()->json(['success' => false, 'message' => 'Cannot add participants to a 1:1 call'], 422);
    }

    /**
     * Leave a call.
     * POST /api/calls/{id}/leave
     */
    public function leave(string $id, Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $resolved = $this->resolveCall($id);

        if (!$resolved) {
            return response()->json(['success' => false, 'message' => 'Call not found'], 404);
        }

        if ($resolved['type'] === 'group') {
            $groupCall = $resolved['model'];
            $groupCall->removeParticipant($userId);

            broadcast(new ParticipantLeft($groupCall->call_id, $userId));

            return response()->json(['success' => true]);
        }

        // 1:1: leaving = ending
        $call = $resolved['model'];
        if ($call->isActive()) {
            $call->end(Call::END_COMPLETED);
            broadcast(new CallEnded($call->call_id, $userId, 'ended_by_user'))->toOthers();
        }

        return response()->json(['success' => true]);
    }

    /**
     * List participants of a call.
     * GET /api/calls/{id}/participants
     */
    public function getParticipants(string $id, Request $request): JsonResponse
    {
        $resolved = $this->resolveCall($id);

        if (!$resolved) {
            return response()->json(['success' => false, 'message' => 'Call not found'], 404);
        }

        if ($resolved['type'] === '1to1') {
            $call = $resolved['model']->load(['caller', 'callee']);
            $participants = collect();

            if ($call->caller) {
                $participants->push([
                    'user_id' => $call->caller_id,
                    'user_name' => trim($call->caller->first_name . ' ' . $call->caller->last_name),
                    'avatar_url' => $call->caller->profile_photo_path
                        ? asset('storage/' . $call->caller->profile_photo_path) : '',
                    'role' => 'caller',
                    'joined_at' => $call->started_at?->toIso8601String(),
                    'left_at' => $call->ended_at?->toIso8601String(),
                ]);
            }

            if ($call->callee) {
                $participants->push([
                    'user_id' => $call->callee_id,
                    'user_name' => trim($call->callee->first_name . ' ' . $call->callee->last_name),
                    'avatar_url' => $call->callee->profile_photo_path
                        ? asset('storage/' . $call->callee->profile_photo_path) : '',
                    'role' => 'callee',
                    'joined_at' => $call->answered_at?->toIso8601String(),
                    'left_at' => $call->ended_at?->toIso8601String(),
                ]);
            }

            return response()->json(['success' => true, 'data' => $participants]);
        }

        // Group call
        $groupCall = $resolved['model'];
        $query = GroupCallParticipant::where('group_call_id', $groupCall->id)
            ->with('user:id,first_name,last_name,username,profile_photo_path');

        if ($request->boolean('joined_only')) {
            $query->where('status', 'joined')->whereNull('left_at');
        }

        $participants = $query->get()->map(fn($p) => [
            'user_id' => $p->user_id,
            'user_name' => $p->user ? trim($p->user->first_name . ' ' . $p->user->last_name) : '',
            'avatar_url' => $p->user?->profile_photo_path
                ? asset('storage/' . $p->user->profile_photo_path) : '',
            'role' => $p->role,
            'status' => $p->status,
            'joined_at' => $p->joined_at?->toIso8601String(),
            'left_at' => $p->left_at?->toIso8601String(),
            'hand_raised_at' => $p->hand_raised_at?->toIso8601String(),
            'is_muted' => $p->is_muted,
            'is_video_off' => $p->is_video_off,
        ]);

        return response()->json(['success' => true, 'data' => $participants]);
    }

    // ==================== Reactions & Raise Hand ====================

    /**
     * Send a reaction during a call (ephemeral).
     * POST /api/calls/{id}/reactions
     */
    public function reactions(string $id, Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $resolved = $this->resolveCall($id);

        if (!$resolved) {
            return response()->json(['success' => false, 'message' => 'Call not found'], 404);
        }

        $validated = $request->validate([
            'emoji' => 'required|string|max:8',
        ]);

        $info = $this->userInfo($userId);

        broadcast(new CallReaction(
            $resolved['uuid'],
            $userId,
            $info['name'],
            $validated['emoji']
        ))->toOthers();

        return response()->json(['success' => true], 202);
    }

    /**
     * Toggle raise hand.
     * POST /api/calls/{id}/raise-hand
     */
    public function raiseHand(string $id, Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $resolved = $this->resolveCall($id);

        if (!$resolved) {
            return response()->json(['success' => false, 'message' => 'Call not found'], 404);
        }

        $validated = $request->validate([
            'raised' => 'required|boolean',
        ]);

        // For group calls, persist in DB
        if ($resolved['type'] === 'group') {
            $participant = GroupCallParticipant::where('group_call_id', $resolved['model']->id)
                ->where('user_id', $userId)
                ->first();

            if ($participant) {
                $participant->update([
                    'hand_raised_at' => $validated['raised'] ? now() : null,
                ]);
            }
        }

        broadcast(new RaiseHand($resolved['uuid'], $userId, $validated['raised']));

        return response()->json(['success' => true], 202);
    }

    // ==================== Missed-Call Voice Message ====================

    /**
     * Upload a voice message for a missed call.
     * POST /api/calls/{id}/missed-call-voice-message
     */
    public function missedCallVoiceMessage(string $id, Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $resolved = $this->resolveCall($id);

        if (!$resolved || $resolved['type'] !== '1to1') {
            return response()->json(['success' => false, 'message' => 'Call not found'], 404);
        }

        $call = $resolved['model'];

        $request->validate([
            'voice' => 'required|file|max:5120|mimetypes:audio/webm,audio/ogg,audio/mpeg,audio/mp4,audio/wav',
            'duration_seconds' => 'nullable|numeric|min:0|max:120',
        ]);

        if ($userId !== $call->caller_id) {
            return response()->json(['success' => false, 'message' => 'Only the caller can leave a voice message'], 403);
        }

        if ($call->status !== Call::STATUS_MISSED) {
            return response()->json(['success' => false, 'message' => 'Voice messages can only be left for missed calls'], 422);
        }

        // Idempotent: one voice message per call
        $existing = Message::where('call_session_id', $call->id)
            ->where('message_type', 'missed_call_voice')
            ->first();

        if ($existing) {
            return response()->json(['success' => false, 'message' => 'Already left a voice message for this call'], 422);
        }

        $file = $request->file('voice');
        $path = $file->store("calls/missed-voice/{$call->id}", 'public');

        $conversation = Conversation::getOrCreatePrivate($call->caller_id, $call->callee_id);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $call->caller_id,
            'content' => 'Voice message after missed call',
            'message_type' => 'missed_call_voice',
            'media_path' => $path,
            'media_type' => $file->getMimeType(),
            'call_session_id' => $call->id,
        ]);

        $conversation->updateLastMessage($message);

        ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $call->callee_id)
            ->increment('unread_count');

        broadcast(new \App\Events\NewMessage($message->load('sender')));

        \App\Jobs\SendMessageNotification::dispatch($message, $call->caller_id, $call->callee_id);

        $durationSeconds = $request->input('duration_seconds');

        return response()->json([
            'success' => true,
            'data' => [
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'call_id' => $call->call_id,
                'attachment_url' => asset('storage/' . $path),
                'duration_seconds' => $durationSeconds ? (float) $durationSeconds : null,
                'created_at' => $message->created_at->toIso8601String(),
            ],
        ], 201);
    }

    // ==================== Legacy / Convenience ====================

    /**
     * Polling fallback for incoming calls.
     * GET /api/calls/incoming
     */
    public function incoming(Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);

        $call = Call::where('callee_id', $userId)
            ->whereIn('status', [Call::STATUS_PENDING, Call::STATUS_RINGING])
            ->with('caller')
            ->first();

        return response()->json([
            'success' => true,
            'has_incoming' => $call !== null,
            'data' => $call ? [
                'call_id' => $call->call_id,
                'type' => $call->type,
                'caller_id' => $call->caller_id,
                'caller' => $call->caller,
                'created_at' => $call->created_at->toIso8601String(),
            ] : null,
        ]);
    }

    /**
     * Mark a call as missed (used by RingTimeoutJob or client).
     * POST /api/calls/{id}/missed
     */
    public function markMissed(string $id): JsonResponse
    {
        $resolved = $this->resolveCall($id);

        if (!$resolved || $resolved['type'] !== '1to1') {
            return response()->json(['success' => false, 'message' => 'Call not found'], 404);
        }

        $call = $resolved['model'];

        if (in_array($call->status, [Call::STATUS_PENDING, Call::STATUS_RINGING])) {
            $call->miss();
            broadcast(new CallEnded($call->call_id, $call->caller_id, 'no_answer'));
            SendCallNotification::dispatch($call->fresh(), 'call_missed');
        }

        return response()->json(['success' => true]);
    }

    /**
     * Get active group call for a conversation.
     * GET /api/calls/group/active/{conversationId}
     */
    public function getActiveGroupCall(int $conversationId): JsonResponse
    {
        $groupCall = GroupCall::where('conversation_id', $conversationId)
            ->where('status', 'active')
            ->with('participants.user')
            ->first();

        return response()->json([
            'success' => true,
            'has_active_call' => $groupCall !== null,
            'data' => $groupCall ? [
                'call_id' => $groupCall->call_id,
                'type' => $groupCall->type,
                'status' => $groupCall->status,
                'created_at' => $groupCall->created_at->toIso8601String(),
            ] : null,
        ]);
    }

    /**
     * Toggle mute in group call.
     * POST /api/calls/{id}/mute
     */
    public function toggleMute(string $id, Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $resolved = $this->resolveCall($id);

        if (!$resolved || $resolved['type'] !== 'group') {
            return response()->json(['success' => false, 'message' => 'Group call not found'], 404);
        }

        $participant = GroupCallParticipant::where('group_call_id', $resolved['model']->id)
            ->where('user_id', $userId)
            ->firstOrFail();

        $participant->toggleMute();

        return response()->json(['success' => true, 'is_muted' => $participant->fresh()->is_muted]);
    }

    /**
     * Toggle video in group call.
     * POST /api/calls/{id}/video
     */
    public function toggleVideo(string $id, Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $resolved = $this->resolveCall($id);

        if (!$resolved || $resolved['type'] !== 'group') {
            return response()->json(['success' => false, 'message' => 'Group call not found'], 404);
        }

        $participant = GroupCallParticipant::where('group_call_id', $resolved['model']->id)
            ->where('user_id', $userId)
            ->firstOrFail();

        $participant->toggleVideo();

        return response()->json(['success' => true, 'is_video_off' => $participant->fresh()->is_video_off]);
    }

    // ================================================================
    // Legacy 1:1 Endpoints (Section 2 – CallService, no auth token)
    // ================================================================

    /**
     * POST /api/calls/initiate (legacy create)
     */
    public function initiate(Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'callee_id' => 'required|integer|exists:user_profiles,id',
            'type' => 'required|in:voice,video',
        ]);

        $calleeId = (int) $validated['callee_id'];

        if ($userId === $calleeId) {
            return response()->json(['success' => false, 'message' => 'Cannot call yourself'], 400);
        }

        if (BlockedUser::isEitherBlocked($userId, $calleeId)) {
            return response()->json(['success' => false, 'message' => 'Cannot call this user'], 403);
        }

        $existingCall = Call::where('callee_id', $calleeId)
            ->whereIn('status', ['pending', 'ringing', 'answered'])
            ->first();

        if ($existingCall) {
            return response()->json([
                'success' => false,
                'message' => 'User is on another call',
                'status' => 'busy',
            ], 400);
        }

        $call = Call::create([
            'caller_id' => $userId,
            'callee_id' => $calleeId,
            'type' => $validated['type'],
            'status' => Call::STATUS_RINGING,
        ]);

        broadcast(CallIncoming::fromCall($call));
        SendCallNotification::dispatch($call, 'call_incoming');
        RingTimeoutJob::dispatch($call->id)->delay(now()->addSeconds(45));

        return response()->json([
            'success' => true,
            'data' => $call->fresh()->load(['caller', 'callee']),
        ], 201);
    }

    /**
     * POST /api/calls/{id}/answer (legacy alias for accept – returns full Call model)
     */
    public function legacyAnswer(string $id, Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $resolved = $this->resolveCall($id);

        if (!$resolved) {
            return response()->json(['success' => false, 'message' => 'Call not found'], 404);
        }

        if ($resolved['type'] === '1to1') {
            $call = $resolved['model'];

            if ($call->status !== Call::STATUS_RINGING) {
                return response()->json(['success' => false, 'message' => 'Call is not ringing'], 422);
            }

            $call->answer();
            broadcast(new CallAccepted($call->call_id, $userId, null))->toOthers();

            return response()->json([
                'success' => true,
                'data' => $call->fresh()->load(['caller', 'callee']),
            ]);
        }

        // Group call – delegate to unified accept
        return $this->accept($id, $request);
    }

    /**
     * POST /api/calls/{id}/decline (legacy alias for reject – returns full Call model)
     */
    public function legacyDecline(string $id, Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $resolved = $this->resolveCall($id);

        if (!$resolved) {
            return response()->json(['success' => false, 'message' => 'Call not found'], 404);
        }

        if ($resolved['type'] === '1to1') {
            $call = $resolved['model'];

            if (!in_array($call->status, [Call::STATUS_PENDING, Call::STATUS_RINGING])) {
                return response()->json(['success' => false, 'message' => 'Call cannot be declined'], 422);
            }

            $call->decline();
            broadcast(new CallRejected($call->call_id, $userId))->toOthers();

            return response()->json([
                'success' => true,
                'data' => $call->fresh()->load(['caller', 'callee']),
            ]);
        }

        // Group call – delegate to unified reject
        return $this->reject($id, $request);
    }

    /**
     * GET /api/calls/{id}/status (legacy – returns full Call/GroupCall model)
     */
    public function status(string $id, Request $request): JsonResponse
    {
        $resolved = $this->resolveCall($id);

        if (!$resolved) {
            return response()->json(['success' => false, 'message' => 'Call not found'], 404);
        }

        if ($resolved['type'] === '1to1') {
            return response()->json([
                'success' => true,
                'data' => $resolved['model']->fresh()->load(['caller', 'callee']),
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $resolved['model']->fresh()->load('participants.user'),
        ]);
    }

    /**
     * GET /api/calls/history (legacy call log – same as callLog but at /history path)
     */
    public function history(Request $request): JsonResponse
    {
        return $this->callLog($request);
    }

    // ================================================================
    // Legacy Group Endpoints (Section 3 – CallService group calls)
    // ================================================================

    /**
     * POST /api/calls/group/start
     */
    public function groupStart(Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'conversation_id' => 'required|integer|exists:conversations,id',
            'type' => 'required|in:voice,video',
        ]);

        $conversation = Conversation::findOrFail($validated['conversation_id']);

        // Return existing active call if any
        $existingCall = GroupCall::where('conversation_id', $conversation->id)
            ->where('status', 'active')
            ->first();

        if ($existingCall) {
            return response()->json([
                'success' => true,
                'data' => $existingCall->load('participants.user'),
            ]);
        }

        $groupCall = GroupCall::create([
            'conversation_id' => $conversation->id,
            'initiated_by' => $userId,
            'type' => $validated['type'],
        ]);

        $participant = $groupCall->addParticipant($userId);
        $participant->update(['role' => 'caller']);

        // Invite all other conversation participants
        // Note: $conversation->participants returns UserProfile models via BelongsToMany, use $p->id not $p->user_id
        $invitedUserIds = [];
        foreach ($conversation->participants as $p) {
            if ((int) $p->id !== $userId) {
                GroupCallParticipant::create([
                    'group_call_id' => $groupCall->id,
                    'user_id' => $p->id,
                    'status' => 'invited',
                    'role' => 'callee',
                    'invited_at' => now(),
                ]);
                $invitedUserIds[] = (int) $p->id;
            }
        }

        if (!empty($invitedUserIds)) {
            SendGroupCallNotification::dispatch($groupCall, $userId, $invitedUserIds);

            $initiatorInfo = $this->userInfo($userId);
            foreach ($invitedUserIds as $inviteeId) {
                broadcast(new CallIncoming(
                    $groupCall->call_id,
                    $userId,
                    $initiatorInfo['name'],
                    $initiatorInfo['avatar_url'],
                    $validated['type'],
                    $inviteeId,
                    true
                ));
            }
        }

        return response()->json([
            'success' => true,
            'data' => $groupCall->fresh()->load('participants.user'),
        ], 201);
    }

    /**
     * POST /api/calls/group/{callId}/join (legacy alias for accept)
     */
    public function groupJoin(string $callId, Request $request): JsonResponse
    {
        return $this->accept($callId, $request);
    }

    /**
     * POST /api/calls/group/{callId}/leave
     */
    public function groupLeaveById(string $callId, Request $request): JsonResponse
    {
        return $this->leave($callId, $request);
    }

    /**
     * POST /api/calls/group/{callId}/end
     */
    public function groupEndById(string $callId, Request $request): JsonResponse
    {
        return $this->end($callId, $request);
    }

    /**
     * POST /api/calls/group/{callId}/toggle-mute (legacy – accepts explicit 'muted' bool)
     */
    public function groupToggleMuteById(string $callId, Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $resolved = $this->resolveCall($callId);

        if (!$resolved || $resolved['type'] !== 'group') {
            return response()->json(['success' => false, 'message' => 'Group call not found'], 404);
        }

        $participant = GroupCallParticipant::where('group_call_id', $resolved['model']->id)
            ->where('user_id', $userId)
            ->first();

        if (!$participant) {
            return response()->json(['success' => false, 'message' => 'Not in call'], 404);
        }

        if ($request->has('muted')) {
            $participant->update(['is_muted' => (bool) $request->input('muted')]);
        } else {
            $participant->toggleMute();
        }

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/calls/group/{callId}/toggle-video (legacy – accepts explicit 'video_off' bool)
     */
    public function groupToggleVideoById(string $callId, Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $resolved = $this->resolveCall($callId);

        if (!$resolved || $resolved['type'] !== 'group') {
            return response()->json(['success' => false, 'message' => 'Group call not found'], 404);
        }

        $participant = GroupCallParticipant::where('group_call_id', $resolved['model']->id)
            ->where('user_id', $userId)
            ->first();

        if (!$participant) {
            return response()->json(['success' => false, 'message' => 'Not in call'], 404);
        }

        if ($request->has('video_off')) {
            $participant->update(['is_video_off' => (bool) $request->input('video_off')]);
        } else {
            $participant->toggleVideo();
        }

        return response()->json(['success' => true]);
    }

    // ================================================================
    // Alternate Group API (Section 4 – GroupCallService)
    // ================================================================

    /**
     * POST /api/calls/group (create or join existing group call)
     * Body: { conversation_id, user_id }
     * Returns: { success, call_id, room_token, participants }
     */
    public function altGroupCreate(Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'conversation_id' => 'required|integer|exists:conversations,id',
        ]);

        $conversation = Conversation::findOrFail($validated['conversation_id']);

        // Check for existing active group call – join it
        $existingCall = GroupCall::where('conversation_id', $conversation->id)
            ->where('status', 'active')
            ->first();

        if ($existingCall) {
            $existingCall->addParticipant($userId);

            $participants = GroupCallParticipant::where('group_call_id', $existingCall->id)
                ->where('status', 'joined')
                ->with('user:id,first_name,last_name,profile_photo_path')
                ->get()
                ->map(fn($p) => [
                    'user_id' => $p->user_id,
                    'display_name' => $p->user ? trim($p->user->first_name . ' ' . $p->user->last_name) : '',
                    'avatar_url' => $p->user?->profile_photo_path
                        ? asset('storage/' . $p->user->profile_photo_path) : '',
                    'is_muted' => (bool) $p->is_muted,
                    'video_enabled' => !((bool) $p->is_video_off),
                ]);

            return response()->json([
                'success' => true,
                'call_id' => $existingCall->call_id,
                'room_token' => null,
                'participants' => $participants,
            ]);
        }

        // Create new group call
        $groupCall = GroupCall::create([
            'conversation_id' => $conversation->id,
            'initiated_by' => $userId,
            'type' => 'voice',
        ]);

        $groupCall->addParticipant($userId);

        $info = $this->userInfo($userId);

        return response()->json([
            'success' => true,
            'call_id' => $groupCall->call_id,
            'room_token' => null,
            'participants' => [[
                'user_id' => $userId,
                'display_name' => $info['name'],
                'avatar_url' => $info['avatar_url'],
                'is_muted' => false,
                'video_enabled' => true,
            ]],
        ], 201);
    }

    /**
     * POST /api/calls/group/leave (call_id + user_id in body)
     */
    public function altGroupLeave(Request $request): JsonResponse
    {
        $callId = $request->input('call_id');
        if (!$callId) {
            return response()->json(['success' => false, 'message' => 'call_id required'], 422);
        }

        return $this->leave((string) $callId, $request);
    }

    /**
     * PATCH /api/calls/group/state (call_id + user_id + muted/video_enabled in body)
     */
    public function altGroupState(Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $callId = $request->input('call_id');

        if (!$callId) {
            return response()->json(['success' => false, 'message' => 'call_id required'], 422);
        }

        $resolved = $this->resolveCall((string) $callId);
        if (!$resolved || $resolved['type'] !== 'group') {
            return response()->json(['success' => false, 'message' => 'Group call not found'], 404);
        }

        $participant = GroupCallParticipant::where('group_call_id', $resolved['model']->id)
            ->where('user_id', $userId)
            ->first();

        if (!$participant) {
            return response()->json(['success' => false, 'message' => 'Not in call'], 404);
        }

        if ($request->has('muted')) {
            $participant->update(['is_muted' => (bool) $request->input('muted')]);
        }

        if ($request->has('video_enabled')) {
            $participant->update(['is_video_off' => !((bool) $request->input('video_enabled'))]);
        }

        return response()->json(['success' => true]);
    }
}
