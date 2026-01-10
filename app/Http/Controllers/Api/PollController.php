<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollVote;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PollController extends Controller
{
    /**
     * Get list of polls.
     */
    public function index(Request $request): JsonResponse
    {
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);
        $status = $request->query('status', 'active'); // active, ended, all
        $groupId = $request->query('group_id');
        $pageId = $request->query('page_id');
        $currentUserId = $request->query('current_user_id');

        $query = Poll::with([
            'creator:id,first_name,last_name,username,profile_photo_path',
            'options'
        ]);

        if ($status === 'active') {
            $query->active();
        } elseif ($status === 'ended') {
            $query->ended();
        }

        if ($groupId) {
            $query->where('group_id', $groupId);
        }

        if ($pageId) {
            $query->where('page_id', $pageId);
        }

        $polls = $query->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        // Add user-specific data
        if ($currentUserId) {
            foreach ($polls as $poll) {
                $poll->has_voted = $poll->hasVoted($currentUserId);
                $poll->user_votes = $poll->getUserVotes($currentUserId);
                $poll->can_vote = $poll->canVote($currentUserId);
                $poll->can_see_results = $poll->canSeeResults($currentUserId);

                // Add percentage to options if can see results
                if ($poll->can_see_results) {
                    foreach ($poll->options as $option) {
                        $option->percentage = $option->percentage;
                    }
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => $polls->items(),
            'meta' => [
                'current_page' => $polls->currentPage(),
                'last_page' => $polls->lastPage(),
                'per_page' => $polls->perPage(),
                'total' => $polls->total(),
            ],
        ]);
    }

    /**
     * Create a new poll.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'creator_id' => 'required|exists:user_profiles,id',
            'question' => 'required|string|max:500',
            'options' => 'required|array|min:2|max:10',
            'options.*' => 'required|string|max:200',
            'post_id' => 'nullable|exists:posts,id',
            'group_id' => 'nullable|exists:groups,id',
            'page_id' => 'nullable|exists:pages,id',
            'ends_at' => 'nullable|date|after:now',
            'is_multiple_choice' => 'boolean',
            'is_anonymous' => 'boolean',
            'show_results_before_voting' => 'boolean',
            'allow_add_options' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $poll = Poll::create([
                'question' => $request->question,
                'creator_id' => $request->creator_id,
                'post_id' => $request->post_id,
                'group_id' => $request->group_id,
                'page_id' => $request->page_id,
                'ends_at' => $request->ends_at,
                'is_multiple_choice' => $request->is_multiple_choice ?? false,
                'is_anonymous' => $request->is_anonymous ?? false,
                'show_results_before_voting' => $request->show_results_before_voting ?? true,
                'allow_add_options' => $request->allow_add_options ?? false,
            ]);

            // Create options
            foreach ($request->options as $index => $optionText) {
                PollOption::create([
                    'poll_id' => $poll->id,
                    'option_text' => $optionText,
                    'order' => $index,
                ]);
            }

            DB::commit();

            $poll->load([
                'creator:id,first_name,last_name,username,profile_photo_path',
                'options'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Poll created successfully',
                'data' => $poll,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create poll: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a single poll.
     */
    public function show(int $id, Request $request): JsonResponse
    {
        $poll = Poll::with([
            'creator:id,first_name,last_name,username,profile_photo_path',
            'options',
            'group:id,name,slug',
            'page:id,name,slug',
        ])->find($id);

        if (!$poll) {
            return response()->json([
                'success' => false,
                'message' => 'Poll not found',
            ], 404);
        }

        $currentUserId = $request->query('current_user_id');
        if ($currentUserId) {
            $poll->has_voted = $poll->hasVoted($currentUserId);
            $poll->user_votes = $poll->getUserVotes($currentUserId);
            $poll->can_vote = $poll->canVote($currentUserId);
            $poll->can_see_results = $poll->canSeeResults($currentUserId);
        } else {
            $poll->can_see_results = $poll->show_results_before_voting || $poll->hasEnded();
        }

        // Add percentage to options if can see results
        if ($poll->can_see_results) {
            foreach ($poll->options as $option) {
                $option->percentage = $option->percentage;
            }
        }

        $poll->is_ended = $poll->hasEnded();

        return response()->json([
            'success' => true,
            'data' => $poll,
        ]);
    }

    /**
     * Update a poll.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $poll = Poll::find($id);
        if (!$poll) {
            return response()->json(['success' => false, 'message' => 'Poll not found'], 404);
        }

        // Can't update poll that has votes
        if ($poll->total_votes > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update poll with existing votes',
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'question' => 'string|max:500',
            'ends_at' => 'nullable|date|after:now',
            'is_multiple_choice' => 'boolean',
            'is_anonymous' => 'boolean',
            'show_results_before_voting' => 'boolean',
            'allow_add_options' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $poll->update($request->only([
            'question', 'ends_at', 'is_multiple_choice', 'is_anonymous',
            'show_results_before_voting', 'allow_add_options'
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Poll updated successfully',
            'data' => $poll->fresh(['creator:id,first_name,last_name,username,profile_photo_path', 'options']),
        ]);
    }

    /**
     * Delete a poll.
     */
    public function destroy(int $id): JsonResponse
    {
        $poll = Poll::find($id);
        if (!$poll) {
            return response()->json(['success' => false, 'message' => 'Poll not found'], 404);
        }

        $poll->delete();

        return response()->json([
            'success' => true,
            'message' => 'Poll deleted successfully',
        ]);
    }

    /**
     * Vote on a poll.
     */
    public function vote(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'option_ids' => 'required|array|min:1',
            'option_ids.*' => 'exists:poll_options,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $poll = Poll::find($id);
        if (!$poll) {
            return response()->json(['success' => false, 'message' => 'Poll not found'], 404);
        }

        // Check if poll has ended
        if ($poll->hasEnded()) {
            return response()->json(['success' => false, 'message' => 'Poll has ended'], 400);
        }

        // Check if multiple choice
        if (!$poll->is_multiple_choice && count($request->option_ids) > 1) {
            return response()->json([
                'success' => false,
                'message' => 'Only one option allowed',
            ], 400);
        }

        // Check if user already voted (for single choice polls)
        if (!$poll->is_multiple_choice && $poll->hasVoted($request->user_id)) {
            return response()->json([
                'success' => false,
                'message' => 'Already voted',
            ], 400);
        }

        // Verify all options belong to this poll
        $optionCount = PollOption::where('poll_id', $id)
            ->whereIn('id', $request->option_ids)
            ->count();

        if ($optionCount !== count($request->option_ids)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid option(s)',
            ], 400);
        }

        try {
            DB::beginTransaction();

            foreach ($request->option_ids as $optionId) {
                // Check if already voted for this option
                $existingVote = PollVote::where('poll_id', $id)
                    ->where('option_id', $optionId)
                    ->where('user_id', $request->user_id)
                    ->exists();

                if (!$existingVote) {
                    PollVote::create([
                        'poll_id' => $id,
                        'option_id' => $optionId,
                        'user_id' => $request->user_id,
                    ]);

                    PollOption::find($optionId)->incrementVotes();
                    $poll->incrementVotes();
                }
            }

            DB::commit();

            $poll->refresh();
            $poll->load('options');

            // Add percentages
            foreach ($poll->options as $option) {
                $option->percentage = $option->percentage;
            }

            return response()->json([
                'success' => true,
                'message' => 'Vote recorded',
                'data' => $poll,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to vote: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove vote from a poll.
     */
    public function unvote(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'option_id' => 'nullable|exists:poll_options,id', // If null, remove all votes
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $poll = Poll::find($id);
        if (!$poll) {
            return response()->json(['success' => false, 'message' => 'Poll not found'], 404);
        }

        if ($poll->hasEnded()) {
            return response()->json(['success' => false, 'message' => 'Poll has ended'], 400);
        }

        try {
            DB::beginTransaction();

            $query = PollVote::where('poll_id', $id)->where('user_id', $request->user_id);

            if ($request->option_id) {
                $query->where('option_id', $request->option_id);
            }

            $votes = $query->get();

            foreach ($votes as $vote) {
                PollOption::find($vote->option_id)->decrementVotes();
                $poll->decrementVotes();
                $vote->delete();
            }

            DB::commit();

            $poll->refresh();
            $poll->load('options');

            foreach ($poll->options as $option) {
                $option->percentage = $option->percentage;
            }

            return response()->json([
                'success' => true,
                'message' => 'Vote(s) removed',
                'data' => $poll,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove vote: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add an option to a poll (if allowed).
     */
    public function addOption(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'option_text' => 'required|string|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $poll = Poll::find($id);
        if (!$poll) {
            return response()->json(['success' => false, 'message' => 'Poll not found'], 404);
        }

        if (!$poll->allow_add_options) {
            return response()->json([
                'success' => false,
                'message' => 'Adding options not allowed',
            ], 400);
        }

        if ($poll->hasEnded()) {
            return response()->json(['success' => false, 'message' => 'Poll has ended'], 400);
        }

        // Check for duplicate option
        $exists = $poll->options()->where('option_text', $request->option_text)->exists();
        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Option already exists',
            ], 400);
        }

        $maxOrder = $poll->options()->max('order') ?? -1;

        $option = PollOption::create([
            'poll_id' => $id,
            'option_text' => $request->option_text,
            'order' => $maxOrder + 1,
            'added_by' => $request->user_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Option added',
            'data' => $option,
        ], 201);
    }

    /**
     * Get voters for an option (if not anonymous).
     */
    public function voters(Request $request, int $id, int $optionId): JsonResponse
    {
        $poll = Poll::find($id);
        if (!$poll) {
            return response()->json(['success' => false, 'message' => 'Poll not found'], 404);
        }

        if ($poll->is_anonymous) {
            return response()->json([
                'success' => false,
                'message' => 'Poll is anonymous',
            ], 400);
        }

        $option = PollOption::where('poll_id', $id)->where('id', $optionId)->first();
        if (!$option) {
            return response()->json(['success' => false, 'message' => 'Option not found'], 404);
        }

        $voters = $option->votes()
            ->with('user:id,first_name,last_name,username,profile_photo_path')
            ->get()
            ->pluck('user');

        return response()->json([
            'success' => true,
            'data' => $voters,
        ]);
    }

    /**
     * End a poll early.
     */
    public function endPoll(int $id): JsonResponse
    {
        $poll = Poll::find($id);
        if (!$poll) {
            return response()->json(['success' => false, 'message' => 'Poll not found'], 404);
        }

        if ($poll->hasEnded()) {
            return response()->json(['success' => false, 'message' => 'Poll already ended'], 400);
        }

        $poll->update(['ends_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Poll ended',
        ]);
    }

    /**
     * Get user's polls.
     */
    public function userPolls(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'User ID required'], 400);
        }

        $filter = $request->query('filter'); // 'voted' or null (created)

        if ($filter === 'voted') {
            // Get polls user has voted on
            $pollIds = PollVote::where('user_id', $userId)
                ->distinct()
                ->pluck('poll_id');

            $polls = Poll::whereIn('id', $pollIds)
                ->with([
                    'creator:id,first_name,last_name,username,profile_photo_path',
                    'options'
                ])
                ->orderBy('created_at', 'desc')
                ->get();
        } else {
            // Get polls created by user
            $polls = Poll::where('creator_id', $userId)
                ->with([
                    'creator:id,first_name,last_name,username,profile_photo_path',
                    'options'
                ])
                ->orderBy('created_at', 'desc')
                ->get();
        }

        // Add user-specific data and percentages
        foreach ($polls as $poll) {
            $poll->has_voted = $poll->hasVoted($userId);
            $poll->user_votes = $poll->getUserVotes($userId);
            foreach ($poll->options as $option) {
                $option->percentage = $option->percentage;
            }
        }

        return response()->json([
            'success' => true,
            'data' => $polls,
        ]);
    }

    /**
     * Get all voters for a poll (if not anonymous).
     */
    public function allVoters(int $id): JsonResponse
    {
        $poll = Poll::find($id);
        if (!$poll) {
            return response()->json(['success' => false, 'message' => 'Poll not found'], 404);
        }

        if ($poll->is_anonymous) {
            return response()->json([
                'success' => false,
                'message' => 'Poll is anonymous',
            ], 400);
        }

        $voters = PollVote::where('poll_id', $id)
            ->with('user:id,first_name,last_name,username,profile_photo_path')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($vote) {
                return [
                    'id' => $vote->id,
                    'option_id' => $vote->option_id,
                    'created_at' => $vote->created_at,
                    'user' => $vote->user,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $voters,
        ]);
    }

    /**
     * Close/deactivate a poll.
     */
    public function closePoll(int $id): JsonResponse
    {
        $poll = Poll::find($id);
        if (!$poll) {
            return response()->json(['success' => false, 'message' => 'Poll not found'], 404);
        }

        $poll->update([
            'status' => 'closed',
            'ends_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Poll closed',
        ]);
    }
}
