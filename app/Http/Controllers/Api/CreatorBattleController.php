<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreatorBattle;
use App\Models\CreatorBattleVote;
use Illuminate\Http\Request;

class CreatorBattleController extends Controller
{
    public function index()
    {
        $battles = CreatorBattle::where('status', 'active')
            ->with(['creatorA:id,name', 'creatorB:id,name'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($b) => $this->formatBattle($b));

        return response()->json(['data' => $battles]);
    }

    public function show(int $id)
    {
        $battle = CreatorBattle::with(['creatorA:id,name', 'creatorB:id,name'])->findOrFail($id);

        return response()->json(['data' => $this->formatBattle($battle)]);
    }

    public function vote(Request $request, int $id)
    {
        $validated = $request->validate([
            'side' => 'required|string|in:a,b',
        ]);

        $battle = CreatorBattle::findOrFail($id);

        if ($battle->status !== 'active') {
            return response()->json(['message' => 'Battle is not active'], 422);
        }

        $userId = $request->user()->id;

        $existing = CreatorBattleVote::where('battle_id', $id)->where('user_id', $userId)->first();

        if ($existing) {
            $oldSide = $existing->side;
            $existing->update(['side' => $validated['side']]);

            if ($oldSide !== $validated['side']) {
                $battle->decrement('votes_' . $oldSide);
                $battle->increment('votes_' . $validated['side']);
            }
        } else {
            CreatorBattleVote::create([
                'battle_id' => $id,
                'user_id' => $userId,
                'side' => $validated['side'],
            ]);
            $battle->increment('votes_' . $validated['side']);
        }

        $battle->refresh();

        return response()->json(['data' => $this->formatBattle($battle)]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'opponent_id' => 'required|integer|exists:users,id',
            'topic' => 'required|string|max:255',
            'post_a_id' => 'nullable|integer|exists:posts,id',
            'duration_hours' => 'sometimes|integer|min:1|max:168',
        ]);

        $userId = $request->user()->id;

        if ($userId == $validated['opponent_id']) {
            return response()->json(['message' => 'Cannot battle yourself'], 422);
        }

        $battle = \App\Models\CreatorBattle::create([
            'creator_a_id' => $userId,
            'creator_b_id' => $validated['opponent_id'],
            'post_a_id' => $validated['post_a_id'] ?? null,
            'topic' => $validated['topic'],
            'status' => 'active',
            'votes_a' => 0,
            'votes_b' => 0,
            'ends_at' => now()->addHours($validated['duration_hours'] ?? 24),
        ]);

        \App\Services\FcmNotificationService::sendToUser($validated["opponent_id"], "battle_invitation", ["battle_id" => $battle->id], "Battle Challenge", $request->user()->name . " challenged you!");

        return response()->json(["data" => $this->formatBattle($battle->load(['creatorA:id,name', 'creatorB:id,name']))], 201);
    }

    private function formatBattle(CreatorBattle $b): array
    {
        return [
            'id' => $b->id,
            'creator_a_id' => $b->creator_a_id,
            'creator_b_id' => $b->creator_b_id,
            'creator_a_name' => $b->creatorA->name ?? '',
            'creator_b_name' => $b->creatorB->name ?? '',
            'post_a_id' => $b->post_a_id,
            'post_b_id' => $b->post_b_id,
            'topic' => $b->topic,
            'status' => $b->status,
            'votes_a' => $b->votes_a,
            'votes_b' => $b->votes_b,
            'ends_at' => $b->ends_at?->toISOString(),
            'created_at' => $b->created_at?->toISOString(),
        ];
    }
}
