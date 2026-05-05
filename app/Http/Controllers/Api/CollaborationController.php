<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CollaborationSuggestion;
use Illuminate\Http\Request;

class CollaborationController extends Controller
{
    public function suggestions(int $id)
    {
        $suggestions = CollaborationSuggestion::where('user_id', $id)
            ->where('status', 'pending')
            ->with('suggestedUser:id,name')
            ->orderByDesc('compatibility_score')
            ->limit(10)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'user_id' => $s->user_id,
                'suggested_user_id' => $s->suggested_user_id,
                'suggested_user_name' => $s->suggestedUser->name ?? '',
                'suggested_user_avatar_url' => null,
                'reason' => $s->reason,
                'compatibility_score' => (float) $s->compatibility_score,
                'status' => $s->status,
                'created_at' => $s->created_at?->toISOString(),
            ]);

        return response()->json(['data' => $suggestions]);
    }

    public function respond(Request $request, int $id)
    {
        $validated = $request->validate([
            'action' => 'required|string|in:accept,dismiss',
        ]);

        $suggestion = CollaborationSuggestion::findOrFail($id);
        $suggestion->update([
            'status' => $validated['action'] === 'accept' ? 'accepted' : 'dismissed',
        ]);

        return response()->json(['data' => ['success' => true]]);
    }
}
