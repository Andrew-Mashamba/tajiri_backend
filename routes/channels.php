<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

/*
|--------------------------------------------------------------------------
| Public Channels (No Authentication Required)
|--------------------------------------------------------------------------
*/

/**
 * Global live streams channel - broadcasts to ALL users on the Live tab.
 * Used for:
 * - viewer_count_updated: Real-time viewer counts for all streams
 * - stream_status_changed: When streams go live or end
 * - new_stream_started: When a new stream goes live
 */
Broadcast::channel('live-streams', function () {
    // Public channel - no authentication needed
    // Anyone can listen to this channel
    return true;
});

/**
 * Stream-specific public channel - broadcasts to all viewers of a specific stream.
 * Used for:
 * - viewer_count_updated: Viewer count for this stream
 * - status_changed: Stream status changes
 * - new_comment: New comments on the stream
 * - gift_sent: Gifts sent to the streamer
 * - reaction: Reactions from viewers
 * - poll_updated: Poll updates
 * - question_updated: Q&A updates
 */
Broadcast::channel('stream.{streamId}', function ($user, $streamId) {
    // Public channel - anyone can watch streams
    return true;
});

/*
|--------------------------------------------------------------------------
| Private Channels (Authentication Required)
|--------------------------------------------------------------------------
*/

/**
 * User's personal notifications channel.
 * Only the user themselves can listen to this channel.
 */
Broadcast::channel('user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

/**
 * Conversation channel for real-time messaging.
 * Only participants of the conversation can listen.
 * Used for:
 * - new_message: New message sent in conversation
 * - message_updated: Message edited or reactions changed
 * - message_deleted: Message deleted
 * - typing: User typing indicator
 */
Broadcast::channel('conversation.{conversationId}', function ($user, $conversationId) {
    $isParticipant = \App\Models\ConversationParticipant::where('conversation_id', $conversationId)
        ->where('user_id', $user->id)
        ->exists();

    if ($isParticipant) {
        return [
            'id' => $user->id,
            'name' => $user->first_name . ' ' . $user->last_name,
        ];
    }

    return false;
});

/**
 * Streamer's private channel for stream management.
 * Only the stream owner can listen to this channel.
 */
Broadcast::channel('streamer.{streamId}', function ($user, $streamId) {
    $stream = \App\Models\LiveStream::find($streamId);
    return $stream && (int) $user->id === (int) $stream->user_id;
});

/**
 * Co-host channel for a stream.
 * Only the stream owner and approved co-hosts can listen.
 */
Broadcast::channel('stream.{streamId}.cohosts', function ($user, $streamId) {
    $stream = \App\Models\LiveStream::find($streamId);

    if (!$stream) {
        return false;
    }

    // Stream owner
    if ((int) $user->id === (int) $stream->user_id) {
        return ['id' => $user->id, 'role' => 'owner'];
    }

    // Approved co-host
    $cohost = $stream->cohosts()
        ->where('user_id', $user->id)
        ->where('status', 'accepted')
        ->first();

    if ($cohost) {
        return ['id' => $user->id, 'role' => 'cohost'];
    }

    return false;
});

/**
 * Call signaling channel.
 * Channel: private-call.{callId} where callId is the UUID.
 * Only participants of the call can listen.
 * Used for: signaling.offer, signaling.answer, signaling.ice_candidate,
 *           call.accepted, call.rejected, call.ended, call.incoming,
 *           call.reaction, call.raise_hand, call.participant_added, call.participant_left
 */
Broadcast::channel('call.{callId}', function ($user, $callId) {
    // Try by UUID first (new Flutter app uses UUIDs)
    $call = \App\Models\Call::where('call_id', $callId)->first();
    if (!$call && is_numeric($callId)) {
        $call = \App\Models\Call::find((int) $callId);
    }

    if ($call && ((int) $user->id === (int) $call->caller_id || (int) $user->id === (int) $call->callee_id)) {
        return ['id' => $user->id, 'name' => $user->first_name . ' ' . $user->last_name];
    }

    // Check group calls by UUID
    $groupCall = \App\Models\GroupCall::where('call_id', $callId)->first();
    if (!$groupCall && is_numeric($callId)) {
        $groupCall = \App\Models\GroupCall::find((int) $callId);
    }

    if ($groupCall) {
        $isParticipant = \App\Models\GroupCallParticipant::where('group_call_id', $groupCall->id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['joined', 'invited', 'ringing'])
            ->exists();

        if ($isParticipant) {
            return ['id' => $user->id, 'name' => $user->first_name . ' ' . $user->last_name];
        }
    }

    return false;
});

/**
 * Battle channel for stream battles.
 * Only participants and viewers of the battle can listen.
 */
Broadcast::channel('battle.{battleId}', function ($user, $battleId) {
    // Battles are public - anyone can watch
    return true;
});

/*
|--------------------------------------------------------------------------
| Presence Channels (Shows who is online)
|--------------------------------------------------------------------------
*/

/**
 * Presence channel for stream viewers.
 * Shows who is currently watching a stream.
 * Requires authentication to show user info.
 */
Broadcast::channel('presence.stream.{streamId}', function ($user, $streamId) {
    // Return user info for presence
    return [
        'id' => $user->id,
        'name' => $user->first_name . ' ' . $user->last_name,
        'avatar' => $user->profile_photo_path,
    ];
});
