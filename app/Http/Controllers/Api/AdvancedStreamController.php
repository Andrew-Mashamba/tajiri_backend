<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LiveStream;
use App\Models\StreamPoll;
use App\Models\StreamPollOption;
use App\Models\StreamPollVote;
use App\Models\StreamQuestion;
use App\Models\StreamQuestionUpvote;
use App\Models\StreamSuperChat;
use App\Models\StreamBattle;
use App\Models\StreamReaction;
use App\Services\WebSocket\WebSocketBroadcaster;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AdvancedStreamController extends Controller
{
    // ==================== REACTIONS ====================

    public function storeReaction(int $streamId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'reaction_type' => 'required|string|in:heart,fire,clap,wow,laugh,sad',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $stream = LiveStream::find($streamId);
        if (!$stream) {
            return response()->json(['success' => false, 'message' => 'Stream not found'], 404);
        }

        // Store individual reaction
        StreamReaction::create([
            'stream_id' => $streamId,
            'user_id' => $request->user_id,
            'reaction_type' => $request->reaction_type,
            'created_at' => now(),
        ]);

        // Update reaction counts on stream
        $counts = $stream->reaction_counts ?? [];
        $counts[$request->reaction_type] = ($counts[$request->reaction_type] ?? 0) + 1;
        $stream->update(['reaction_counts' => $counts]);

        // Broadcast
        WebSocketBroadcaster::reaction($streamId, $request->user_id, $request->reaction_type);

        return response()->json(['success' => true, 'message' => 'Reaction sent successfully']);
    }

    // ==================== POLLS ====================

    public function createPoll(int $streamId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'question' => 'required|string|max:255',
            'options' => 'required|array|min:2|max:6',
            'options.*' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $stream = LiveStream::find($streamId);
        if (!$stream) {
            return response()->json(['success' => false, 'message' => 'Stream not found'], 404);
        }

        // Only broadcaster can create polls
        if ($stream->user_id !== (int) $request->user_id) {
            return response()->json(['success' => false, 'message' => 'Only broadcaster can create polls'], 403);
        }

        // Close any existing open poll
        StreamPoll::where('stream_id', $streamId)
            ->where('is_closed', false)
            ->update(['is_closed' => true, 'closed_at' => now()]);

        $poll = StreamPoll::create([
            'stream_id' => $streamId,
            'question' => $request->question,
            'created_by' => $request->user_id,
            'created_at' => now(),
        ]);

        foreach ($request->options as $optionText) {
            StreamPollOption::create([
                'poll_id' => $poll->id,
                'text' => $optionText,
            ]);
        }

        $poll->load('options');

        // Broadcast
        WebSocketBroadcaster::broadcast($streamId, 'poll_created', [
            'poll_id' => $poll->id,
            'question' => $poll->question,
            'options' => $poll->options->map(fn($o) => ['id' => $o->id, 'text' => $o->text, 'votes' => 0]),
        ]);

        return response()->json([
            'success' => true,
            'poll' => [
                'id' => $poll->id,
                'stream_id' => $poll->stream_id,
                'question' => $poll->question,
                'options' => $poll->options->map(fn($o) => ['id' => $o->id, 'text' => $o->text, 'votes' => 0]),
                'is_closed' => false,
                'created_by' => $poll->created_by,
                'created_at' => $poll->created_at->toIso8601String(),
            ],
        ], 201);
    }

    public function votePoll(int $streamId, int $pollId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'option_id' => 'required|exists:stream_poll_options,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $poll = StreamPoll::where('id', $pollId)
            ->where('stream_id', $streamId)
            ->first();

        if (!$poll) {
            return response()->json(['success' => false, 'message' => 'Poll not found'], 404);
        }

        if ($poll->is_closed) {
            return response()->json(['success' => false, 'message' => 'Poll is closed'], 400);
        }

        // Check if user already voted
        $existingVote = StreamPollVote::where('poll_id', $pollId)
            ->where('user_id', $request->user_id)
            ->first();

        if ($existingVote) {
            return response()->json(['success' => false, 'message' => 'Already voted'], 400);
        }

        // Verify option belongs to poll
        $option = StreamPollOption::where('id', $request->option_id)
            ->where('poll_id', $pollId)
            ->first();

        if (!$option) {
            return response()->json(['success' => false, 'message' => 'Invalid option'], 400);
        }

        // Record vote
        StreamPollVote::create([
            'poll_id' => $pollId,
            'option_id' => $request->option_id,
            'user_id' => $request->user_id,
            'created_at' => now(),
        ]);

        // Increment vote count
        $option->increment('votes');

        $poll->load('options');

        // Broadcast
        WebSocketBroadcaster::broadcast($streamId, 'poll_vote', [
            'poll_id' => $pollId,
            'option_id' => $request->option_id,
            'user_id' => $request->user_id,
            'votes' => $option->fresh()->votes,
            'timestamp' => now()->toIso8601String(),
        ]);

        return response()->json([
            'success' => true,
            'poll' => [
                'id' => $poll->id,
                'options' => $poll->options->map(fn($o) => ['id' => $o->id, 'text' => $o->text, 'votes' => $o->votes]),
            ],
        ]);
    }

    public function closePoll(int $streamId, int $pollId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $stream = LiveStream::find($streamId);
        if (!$stream || $stream->user_id !== (int) $request->user_id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $poll = StreamPoll::where('id', $pollId)
            ->where('stream_id', $streamId)
            ->first();

        if (!$poll) {
            return response()->json(['success' => false, 'message' => 'Poll not found'], 404);
        }

        $poll->update(['is_closed' => true, 'closed_at' => now()]);
        $poll->load('options');

        // Broadcast
        WebSocketBroadcaster::broadcast($streamId, 'poll_closed', [
            'poll_id' => $pollId,
            'final_results' => $poll->options->map(fn($o) => ['id' => $o->id, 'text' => $o->text, 'votes' => $o->votes]),
        ]);

        return response()->json(['success' => true, 'message' => 'Poll closed successfully']);
    }

    public function getActivePoll(int $streamId): JsonResponse
    {
        $poll = StreamPoll::where('stream_id', $streamId)
            ->where('is_closed', false)
            ->with('options')
            ->first();

        if (!$poll) {
            return response()->json(['success' => true, 'poll' => null]);
        }

        return response()->json([
            'success' => true,
            'poll' => [
                'id' => $poll->id,
                'question' => $poll->question,
                'options' => $poll->options->map(fn($o) => ['id' => $o->id, 'text' => $o->text, 'votes' => $o->votes]),
                'is_closed' => false,
                'created_at' => $poll->created_at->toIso8601String(),
            ],
        ]);
    }

    // ==================== Q&A ====================

    public function submitQuestion(int $streamId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'question' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $stream = LiveStream::find($streamId);
        if (!$stream) {
            return response()->json(['success' => false, 'message' => 'Stream not found'], 404);
        }

        $question = StreamQuestion::create([
            'stream_id' => $streamId,
            'user_id' => $request->user_id,
            'question' => $request->question,
            'created_at' => now(),
        ]);

        $question->load('user:id,first_name,last_name,username,profile_photo_path');

        // Broadcast
        WebSocketBroadcaster::broadcast($streamId, 'question_submitted', [
            'question_id' => $question->id,
            'user_id' => $question->user_id,
            'username' => trim($question->user->first_name . ' ' . $question->user->last_name),
            'question' => $question->question,
            'upvotes' => 0,
            'timestamp' => $question->created_at->toIso8601String(),
        ]);

        return response()->json([
            'success' => true,
            'question' => [
                'id' => $question->id,
                'stream_id' => $question->stream_id,
                'user_id' => $question->user_id,
                'question' => $question->question,
                'upvotes' => 0,
                'is_answered' => false,
                'created_at' => $question->created_at->toIso8601String(),
            ],
        ], 201);
    }

    public function upvoteQuestion(int $streamId, int $questionId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $question = StreamQuestion::where('id', $questionId)
            ->where('stream_id', $streamId)
            ->first();

        if (!$question) {
            return response()->json(['success' => false, 'message' => 'Question not found'], 404);
        }

        // Check if already upvoted
        $existing = StreamQuestionUpvote::where('question_id', $questionId)
            ->where('user_id', $request->user_id)
            ->first();

        if ($existing) {
            // Remove upvote (toggle)
            $existing->delete();
            $question->decrement('upvotes');
        } else {
            StreamQuestionUpvote::create([
                'question_id' => $questionId,
                'user_id' => $request->user_id,
                'created_at' => now(),
            ]);
            $question->increment('upvotes');
        }

        // Broadcast
        WebSocketBroadcaster::broadcast($streamId, 'question_upvoted', [
            'question_id' => $questionId,
            'upvotes' => $question->fresh()->upvotes,
            'timestamp' => now()->toIso8601String(),
        ]);

        return response()->json([
            'success' => true,
            'upvotes' => $question->fresh()->upvotes,
        ]);
    }

    public function answerQuestion(int $streamId, int $questionId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $stream = LiveStream::find($streamId);
        if (!$stream || $stream->user_id !== (int) $request->user_id) {
            return response()->json(['success' => false, 'message' => 'Only broadcaster can mark as answered'], 403);
        }

        $question = StreamQuestion::where('id', $questionId)
            ->where('stream_id', $streamId)
            ->first();

        if (!$question) {
            return response()->json(['success' => false, 'message' => 'Question not found'], 404);
        }

        $question->update(['is_answered' => true, 'answered_at' => now()]);

        // Broadcast
        WebSocketBroadcaster::broadcast($streamId, 'question_answered', [
            'question_id' => $questionId,
            'timestamp' => now()->toIso8601String(),
        ]);

        return response()->json(['success' => true, 'message' => 'Question marked as answered']);
    }

    public function getQuestions(int $streamId): JsonResponse
    {
        $questions = StreamQuestion::where('stream_id', $streamId)
            ->with('user:id,first_name,last_name,username,profile_photo_path')
            ->orderByDesc('upvotes')
            ->get();

        return response()->json([
            'success' => true,
            'questions' => $questions->map(fn($q) => [
                'id' => $q->id,
                'user_id' => $q->user_id,
                'username' => trim($q->user->first_name . ' ' . $q->user->last_name),
                'question' => $q->question,
                'upvotes' => $q->upvotes,
                'is_answered' => $q->is_answered,
                'created_at' => $q->created_at->toIso8601String(),
            ]),
        ]);
    }

    // ==================== SUPER CHAT ====================

    public function sendSuperChat(int $streamId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'message' => 'required|string|max:200',
            'amount' => 'required|numeric|min:1000',
            'payment_method' => 'nullable|string|max:50',
            'payment_reference' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $stream = LiveStream::find($streamId);
        if (!$stream) {
            return response()->json(['success' => false, 'message' => 'Stream not found'], 404);
        }

        // Calculate tier
        $tierInfo = StreamSuperChat::calculateTier($request->amount);

        $superChat = StreamSuperChat::create([
            'stream_id' => $streamId,
            'user_id' => $request->user_id,
            'message' => $request->message,
            'amount' => $request->amount,
            'tier' => $tierInfo['tier'],
            'duration' => $tierInfo['duration'],
            'payment_method' => $request->payment_method,
            'payment_reference' => $request->payment_reference,
            'created_at' => now(),
        ]);

        $superChat->load('user:id,first_name,last_name,username,profile_photo_path');

        // Update stream gifts value
        $stream->increment('gifts_value', $request->amount);

        // Broadcast
        WebSocketBroadcaster::broadcast($streamId, 'super_chat_sent', [
            'user_id' => $superChat->user_id,
            'username' => trim($superChat->user->first_name . ' ' . $superChat->user->last_name),
            'message' => $superChat->message,
            'amount' => (float) $superChat->amount,
            'tier' => $superChat->tier,
            'duration' => $superChat->duration,
            'timestamp' => $superChat->created_at->toIso8601String(),
        ]);

        return response()->json([
            'success' => true,
            'super_chat' => [
                'id' => $superChat->id,
                'stream_id' => $superChat->stream_id,
                'user_id' => $superChat->user_id,
                'message' => $superChat->message,
                'amount' => (float) $superChat->amount,
                'tier' => $superChat->tier,
                'duration' => $superChat->duration,
                'created_at' => $superChat->created_at->toIso8601String(),
            ],
        ], 201);
    }

    // ==================== BATTLES ====================

    public function inviteBattle(int $streamId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'opponent_stream_id' => 'required|exists:live_streams,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $stream = LiveStream::find($streamId);
        if (!$stream || $stream->user_id !== (int) $request->user_id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if (!$stream->isLive()) {
            return response()->json(['success' => false, 'message' => 'Your stream must be live'], 400);
        }

        $opponentStream = LiveStream::find($request->opponent_stream_id);
        if (!$opponentStream || !$opponentStream->isLive()) {
            return response()->json(['success' => false, 'message' => 'Opponent stream not live'], 400);
        }

        // Check for existing pending/active battle
        $existingBattle = StreamBattle::whereIn('status', ['pending', 'active'])
            ->where(function ($q) use ($streamId, $request) {
                $q->where('stream_id_1', $streamId)
                  ->orWhere('stream_id_2', $streamId)
                  ->orWhere('stream_id_1', $request->opponent_stream_id)
                  ->orWhere('stream_id_2', $request->opponent_stream_id);
            })
            ->first();

        if ($existingBattle) {
            return response()->json(['success' => false, 'message' => 'Already in a battle'], 400);
        }

        $battle = StreamBattle::create([
            'stream_id_1' => $streamId,
            'stream_id_2' => $request->opponent_stream_id,
            'status' => 'pending',
        ]);

        // Broadcast invite to opponent
        WebSocketBroadcaster::broadcast($request->opponent_stream_id, 'battle_invite', [
            'battle_id' => $battle->id,
            'opponent_id' => $streamId,
            'opponent_name' => trim($stream->user->first_name . ' ' . $stream->user->last_name),
            'timestamp' => now()->toIso8601String(),
        ]);

        return response()->json([
            'success' => true,
            'battle' => [
                'id' => $battle->id,
                'stream_id_1' => $battle->stream_id_1,
                'stream_id_2' => $battle->stream_id_2,
                'status' => $battle->status,
                'created_at' => $battle->created_at->toIso8601String(),
            ],
        ], 201);
    }

    public function acceptBattle(int $battleId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $battle = StreamBattle::find($battleId);
        if (!$battle || $battle->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Battle not found or not pending'], 404);
        }

        // Verify user owns stream_id_2
        $stream2 = LiveStream::find($battle->stream_id_2);
        if (!$stream2 || $stream2->user_id !== (int) $request->user_id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $battle->update([
            'status' => 'active',
            'started_at' => now(),
        ]);

        $stream1 = LiveStream::with('user:id,first_name,last_name')->find($battle->stream_id_1);

        // Broadcast to both streams
        foreach ([$battle->stream_id_1, $battle->stream_id_2] as $sid) {
            $opponent = $sid === $battle->stream_id_1 ? $stream2 : $stream1;
            WebSocketBroadcaster::broadcast($sid, 'battle_accepted', [
                'battle_id' => $battle->id,
                'opponent_id' => $opponent->id,
                'opponent_name' => trim($opponent->user->first_name . ' ' . $opponent->user->last_name),
                'timestamp' => now()->toIso8601String(),
            ]);
        }

        return response()->json([
            'success' => true,
            'battle' => [
                'id' => $battle->id,
                'status' => 'active',
                'started_at' => $battle->started_at->toIso8601String(),
            ],
        ]);
    }

    public function declineBattle(int $battleId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $battle = StreamBattle::find($battleId);
        if (!$battle || $battle->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Battle not found or not pending'], 404);
        }

        $battle->update(['status' => 'cancelled']);

        return response()->json(['success' => true, 'message' => 'Battle invitation declined']);
    }

    public function getBattle(int $battleId): JsonResponse
    {
        $battle = StreamBattle::with([
            'stream1.user:id,first_name,last_name',
            'stream2.user:id,first_name,last_name',
        ])->find($battleId);

        if (!$battle) {
            return response()->json(['success' => false, 'message' => 'Battle not found'], 404);
        }

        return response()->json([
            'success' => true,
            'battle' => [
                'id' => $battle->id,
                'stream_1' => [
                    'id' => $battle->stream_id_1,
                    'name' => trim($battle->stream1->user->first_name . ' ' . $battle->stream1->user->last_name),
                    'score' => $battle->score_1,
                ],
                'stream_2' => [
                    'id' => $battle->stream_id_2,
                    'name' => trim($battle->stream2->user->first_name . ' ' . $battle->stream2->user->last_name),
                    'score' => $battle->score_2,
                ],
                'status' => $battle->status,
                'winner_stream_id' => $battle->winner_stream_id,
                'started_at' => $battle->started_at?->toIso8601String(),
                'ended_at' => $battle->ended_at?->toIso8601String(),
            ],
        ]);
    }

    public function endBattle(int $battleId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $battle = StreamBattle::find($battleId);
        if (!$battle || $battle->status !== 'active') {
            return response()->json(['success' => false, 'message' => 'Battle not found or not active'], 404);
        }

        // Verify user owns one of the streams
        $stream1 = LiveStream::find($battle->stream_id_1);
        $stream2 = LiveStream::find($battle->stream_id_2);

        if ($stream1->user_id !== (int) $request->user_id && $stream2->user_id !== (int) $request->user_id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $winnerId = $battle->determineWinner();

        $battle->update([
            'status' => 'ended',
            'winner_stream_id' => $winnerId,
            'ended_at' => now(),
        ]);

        // Broadcast to both streams
        foreach ([$battle->stream_id_1, $battle->stream_id_2] as $sid) {
            $myScore = $sid === $battle->stream_id_1 ? $battle->score_1 : $battle->score_2;
            $opponentScore = $sid === $battle->stream_id_1 ? $battle->score_2 : $battle->score_1;

            WebSocketBroadcaster::broadcast($sid, 'battle_ended', [
                'battle_id' => $battle->id,
                'winner_id' => $winnerId,
                'my_score' => $myScore,
                'opponent_score' => $opponentScore,
                'timestamp' => now()->toIso8601String(),
            ]);
        }

        return response()->json([
            'success' => true,
            'battle' => [
                'id' => $battle->id,
                'status' => 'ended',
                'winner_stream_id' => $winnerId,
                'final_scores' => [
                    'stream_1' => $battle->score_1,
                    'stream_2' => $battle->score_2,
                ],
                'ended_at' => $battle->ended_at->toIso8601String(),
            ],
        ]);
    }

    // ==================== HEALTH METRICS ====================

    public function reportHealth(int $streamId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'network_quality' => 'nullable|string|in:excellent,good,poor',
            'bitrate' => 'nullable|integer|min:0',
            'fps' => 'nullable|integer|min:0|max:120',
            'dropped_frames' => 'nullable|integer|min:0',
            'latency' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $stream = LiveStream::find($streamId);
        if (!$stream) {
            return response()->json(['success' => false, 'message' => 'Stream not found'], 404);
        }

        $updates = array_filter([
            'network_quality' => $request->network_quality,
            'average_bitrate' => $request->bitrate,
            'average_fps' => $request->fps,
            'average_latency' => $request->latency,
        ], fn($v) => $v !== null);

        if ($request->dropped_frames !== null) {
            $updates['total_dropped_frames'] = DB::raw("total_dropped_frames + {$request->dropped_frames}");
        }

        if (!empty($updates)) {
            $stream->update($updates);
        }

        return response()->json(['success' => true, 'message' => 'Health metrics recorded']);
    }
}
