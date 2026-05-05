<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SponsoredPost;
use App\Models\CreatorScore;
use Illuminate\Http\Request;

class SponsoredPostController extends Controller
{
    public function active(Request $request)
    {
        $posts = SponsoredPost::where('status', 'active')
            ->with(['sponsor:id,name', 'creator:id,name', 'post'])
            ->orderByDesc('budget')
            ->limit(20)
            ->get()
            ->map(fn ($sp) => [
                'id' => $sp->id,
                'post_id' => $sp->post_id,
                'sponsor_user_id' => $sp->sponsor_user_id,
                'creator_user_id' => $sp->creator_user_id,
                'budget' => (float) $sp->budget,
                'currency' => $sp->currency,
                'status' => $sp->status,
                'tier_required' => $sp->tier_required,
                'impressions_target' => $sp->impressions_target,
                'impressions_delivered' => $sp->impressions_delivered,
                'sponsor_name' => $sp->sponsor->name ?? null,
                'creator_name' => $sp->creator->name ?? null,
                'created_at' => $sp->created_at?->toISOString(),
            ]);

        return response()->json(['data' => $posts]);
    }

    public function creators(Request $request)
    {
        $tier = $request->query('tier', 'star');

        $creators = CreatorScore::where('tier', '>=', $tier)
            ->with('user:id,name')
            ->orderByDesc('score')
            ->limit(50)
            ->get()
            ->map(fn ($cs) => [
                'user_id' => $cs->user_id,
                'name' => $cs->user->name ?? '',
                'avatar_url' => null,
                'tier' => $cs->tier ?? 'star',
                'follower_count' => $cs->community_score ?? 0,
                'avg_engagement_rate' => round(($cs->score ?? 0) / 100, 2),
                'top_category' => $cs->top_category ?? '',
            ]);

        return response()->json(['data' => $creators]);
    }

    public function store(Request $request)
    {
        // Check creator tier - only Star and Legend can create sponsored posts
        $score = CreatorScore::where('user_id', $request->user()->id)->first();
        if (!$score || !in_array($score->tier, ['star', 'legend'])) {
            return response()->json([
                'message' => 'Only Star and Legend tier creators can create sponsored posts. Keep creating great content to level up!',
                'required_tier' => 'star',
                'current_tier' => $score?->tier ?? 'rising',
            ], 403);
        }

        $validated = $request->validate([
            'post_id' => 'required|integer|exists:posts,id',
            'creator_user_id' => 'required|integer|exists:users,id',
            'budget' => 'required|numeric|min:1000',
            'currency' => 'sometimes|string|max:10',
            'tier_required' => 'sometimes|string|in:star,legend',
            'impressions_target' => 'sometimes|integer|min:100',
        ]);

        $validated['sponsor_user_id'] = $request->user()->id;
        $validated['status'] = 'pending';

        $sp = SponsoredPost::create($validated);

        return response()->json(['data' => $sp], 201);
    }

    public function respond(Request $request, int $id)
    {
        $validated = $request->validate([
            'action' => 'required|string|in:accept,reject',
        ]);

        $sp = \App\Models\SponsoredPost::findOrFail($id);

        if ($sp->creator_user_id !== $request->user()->id) {
            return response()->json(['message' => 'Not authorized'], 403);
        }

        if ($sp->status !== 'pending') {
            return response()->json(['message' => 'Can only respond to pending posts'], 422);
        }

        $sp->update([
            'status' => $validated['action'] === 'accept' ? 'active' : 'cancelled',
        ]);

        return response()->json(['data' => ['success' => true, 'status' => $sp->status]]);
    }

    public function creatorSponsored(int $id)
    {
        $posts = SponsoredPost::where('creator_user_id', $id)
            ->with(['sponsor:id,name'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($sp) => [
                'id' => $sp->id,
                'post_id' => $sp->post_id,
                'sponsor_user_id' => $sp->sponsor_user_id,
                'creator_user_id' => $sp->creator_user_id,
                'budget' => (float) $sp->budget,
                'currency' => $sp->currency,
                'status' => $sp->status,
                'tier_required' => $sp->tier_required,
                'impressions_target' => $sp->impressions_target,
                'impressions_delivered' => $sp->impressions_delivered,
                'sponsor_name' => $sp->sponsor->name ?? null,
                'creator_name' => null,
                'created_at' => $sp->created_at?->toISOString(),
            ]);

        return response()->json(['data' => $posts]);
    }
}
