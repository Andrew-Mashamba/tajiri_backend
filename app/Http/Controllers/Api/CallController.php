<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\CallLog;
use App\Models\GroupCall;
use App\Models\GroupCallParticipant;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CallController extends Controller
{
    /**
     * Initiate a call.
     */
    public function initiate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'caller_id' => 'required|exists:user_profiles,id',
            'callee_id' => 'required|exists:user_profiles,id',
            'type' => 'required|in:voice,video',
        ]);

        // Check if callee is already in a call
        $existingCall = Call::where('callee_id', $validated['callee_id'])
            ->whereIn('status', ['pending', 'ringing', 'answered'])
            ->first();

        if ($existingCall) {
            return response()->json([
                'success' => false,
                'message' => 'Mtu yupo kwenye simu nyingine',
                'status' => 'busy',
            ], 400);
        }

        $call = Call::create([
            'caller_id' => $validated['caller_id'],
            'callee_id' => $validated['callee_id'],
            'type' => $validated['type'],
            'status' => Call::STATUS_RINGING,
        ]);

        return response()->json([
            'success' => true,
            'data' => $call->load('caller', 'callee'),
        ], 201);
    }

    /**
     * Answer a call.
     */
    public function answer(int $callId): JsonResponse
    {
        $call = Call::findOrFail($callId);

        if ($call->status !== Call::STATUS_RINGING) {
            return response()->json([
                'success' => false,
                'message' => 'Simu haipatikani',
            ], 400);
        }

        $call->answer();

        return response()->json([
            'success' => true,
            'data' => $call->fresh()->load('caller', 'callee'),
        ]);
    }

    /**
     * Decline a call.
     */
    public function decline(int $callId): JsonResponse
    {
        $call = Call::findOrFail($callId);

        if (!in_array($call->status, [Call::STATUS_PENDING, Call::STATUS_RINGING])) {
            return response()->json([
                'success' => false,
                'message' => 'Simu haipo tena',
            ], 400);
        }

        $call->decline();

        return response()->json([
            'success' => true,
            'message' => 'Simu imekataliwa',
        ]);
    }

    /**
     * End a call.
     */
    public function end(int $callId, Request $request): JsonResponse
    {
        $call = Call::findOrFail($callId);

        if (!$call->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Simu tayari imeisha',
            ], 400);
        }

        $reason = $request->input('reason', Call::END_COMPLETED);
        $call->end($reason);

        return response()->json([
            'success' => true,
            'data' => $call->fresh(),
        ]);
    }

    /**
     * Get call details.
     */
    public function show(int $callId): JsonResponse
    {
        $call = Call::with(['caller', 'callee'])->findOrFail($callId);

        return response()->json([
            'success' => true,
            'data' => $call,
        ]);
    }

    /**
     * Get user's call history.
     */
    public function history(int $userId): JsonResponse
    {
        $logs = CallLog::where('user_id', $userId)
            ->with('otherUser')
            ->orderByDesc('call_time')
            ->paginate(30);

        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }

    /**
     * Get incoming calls (for polling).
     */
    public function incoming(int $userId): JsonResponse
    {
        $call = Call::where('callee_id', $userId)
            ->whereIn('status', [Call::STATUS_PENDING, Call::STATUS_RINGING])
            ->with('caller')
            ->first();

        return response()->json([
            'success' => true,
            'has_incoming' => $call !== null,
            'data' => $call,
        ]);
    }

    /**
     * Mark missed call (after timeout).
     */
    public function markMissed(int $callId): JsonResponse
    {
        $call = Call::findOrFail($callId);

        if (in_array($call->status, [Call::STATUS_PENDING, Call::STATUS_RINGING])) {
            $call->miss();
        }

        return response()->json([
            'success' => true,
        ]);
    }

    // ==================== Group Calls ====================

    /**
     * Start a group call.
     */
    public function startGroupCall(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => 'required|exists:conversations,id',
            'initiated_by' => 'required|exists:user_profiles,id',
            'type' => 'required|in:voice,video',
        ]);

        $conversation = Conversation::findOrFail($validated['conversation_id']);

        // Check if there's an active group call
        $existingCall = GroupCall::where('conversation_id', $conversation->id)
            ->where('status', 'active')
            ->first();

        if ($existingCall) {
            return response()->json([
                'success' => true,
                'message' => 'Simu ya kikundi tayari inaendelea',
                'data' => $existingCall->load('participants.user'),
            ]);
        }

        $groupCall = GroupCall::create([
            'conversation_id' => $conversation->id,
            'initiated_by' => $validated['initiated_by'],
            'type' => $validated['type'],
        ]);

        // Add initiator as first participant
        $groupCall->addParticipant($validated['initiated_by']);

        // Invite all other conversation participants
        foreach ($conversation->participants as $participant) {
            if ($participant->id !== $validated['initiated_by']) {
                GroupCallParticipant::create([
                    'group_call_id' => $groupCall->id,
                    'user_id' => $participant->id,
                    'status' => 'invited',
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $groupCall->load('participants.user'),
        ], 201);
    }

    /**
     * Join a group call.
     */
    public function joinGroupCall(int $callId, Request $request): JsonResponse
    {
        $groupCall = GroupCall::findOrFail($callId);
        $userId = $request->input('user_id');

        if (!$groupCall->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Simu imeisha',
            ], 400);
        }

        $groupCall->addParticipant($userId);

        return response()->json([
            'success' => true,
            'data' => $groupCall->fresh()->load('participants.user'),
        ]);
    }

    /**
     * Leave a group call.
     */
    public function leaveGroupCall(int $callId, Request $request): JsonResponse
    {
        $groupCall = GroupCall::findOrFail($callId);
        $userId = $request->input('user_id');

        $groupCall->removeParticipant($userId);

        return response()->json([
            'success' => true,
        ]);
    }

    /**
     * Decline group call invitation.
     */
    public function declineGroupCall(int $callId, Request $request): JsonResponse
    {
        $groupCall = GroupCall::findOrFail($callId);
        $userId = $request->input('user_id');

        GroupCallParticipant::where('group_call_id', $callId)
            ->where('user_id', $userId)
            ->update(['status' => 'declined']);

        return response()->json([
            'success' => true,
        ]);
    }

    /**
     * End a group call.
     */
    public function endGroupCall(int $callId): JsonResponse
    {
        $groupCall = GroupCall::findOrFail($callId);
        $groupCall->end();

        return response()->json([
            'success' => true,
        ]);
    }

    /**
     * Toggle mute in group call.
     */
    public function toggleMute(int $callId, Request $request): JsonResponse
    {
        $userId = $request->input('user_id');

        $participant = GroupCallParticipant::where('group_call_id', $callId)
            ->where('user_id', $userId)
            ->firstOrFail();

        $participant->toggleMute();

        return response()->json([
            'success' => true,
            'is_muted' => $participant->fresh()->is_muted,
        ]);
    }

    /**
     * Toggle video in group call.
     */
    public function toggleVideo(int $callId, Request $request): JsonResponse
    {
        $userId = $request->input('user_id');

        $participant = GroupCallParticipant::where('group_call_id', $callId)
            ->where('user_id', $userId)
            ->firstOrFail();

        $participant->toggleVideo();

        return response()->json([
            'success' => true,
            'is_video_off' => $participant->fresh()->is_video_off,
        ]);
    }

    /**
     * Get active group call for conversation.
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
            'data' => $groupCall,
        ]);
    }
}
