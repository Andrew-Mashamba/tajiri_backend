<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class UserEventController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:500'],
            'events.*.event_type' => ['required', 'string', 'in:view,like,share,save,scroll_past,dwell,comment,follow,unfollow,depth_milestone,thread_view,thread_read_progress,shop_view,shop_search,product_view,add_to_cart,purchase'],
            'events.*.post_id' => ['nullable', 'integer', 'min:1'],
            'events.*.creator_id' => ['nullable', 'integer', 'min:1'],
            'events.*.timestamp' => ['required', 'date'],
            'events.*.duration_ms' => ['nullable', 'integer', 'min:0'],
            'events.*.session_id' => ['required', 'uuid'],
            'events.*.metadata' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $now = now();
        $rows = [];

        foreach ($validated['events'] as $event) {
            $timestamp = Carbon::parse($event['timestamp'])->setMicrosecond(0);

            $rows[] = [
                'user_id' => $user->id,
                'event_type' => $event['event_type'],
                'post_id' => $event['post_id'] ?? null,
                'creator_id' => $event['creator_id'] ?? null,
                'timestamp' => $timestamp,
                'duration_ms' => isset($event['duration_ms']) ? (int) $event['duration_ms'] : 0,
                'session_id' => $event['session_id'],
                'metadata' => $event['metadata'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $inserted = DB::table('user_events')->insertOrIgnore($rows);

        // Content Engine: fan out events to Redis Stream for signal processing
        try {
            // Resolve source_types for all post_ids in a single query (batch lookup)
            $postIds = collect($validated['events'])->pluck('post_id')->filter()->unique()->values()->toArray();
            $sourceTypeMap = [];
            if (!empty($postIds)) {
                $sourceTypeMap = \App\Models\ContentDocument::whereIn('source_id', $postIds)
                    ->pluck('source_type', 'source_id')
                    ->toArray();
            }

            $ip = $request->ip();
            foreach ($validated['events'] as $event) {
                $postId = $event['post_id'] ?? null;
                if (!$postId) continue;

                Redis::xAdd('engagement:signals', '*', [
                    'user_id' => (string) $user->id,
                    'event_type' => $event['event_type'],
                    'post_id' => (string) $postId,
                    'source_type' => $sourceTypeMap[$postId] ?? 'post',
                    'creator_id' => (string) ($event['creator_id'] ?? ''),
                    'duration_ms' => (string) ($event['duration_ms'] ?? 0),
                    'session_id' => $event['session_id'],
                    'timestamp' => $event['timestamp'],
                    'ip' => $ip ?? '',
                ]);
            }
        } catch (\Throwable $e) {
            // Signal fanout failure is non-critical — don't break event storage
            \Log::warning('Content Engine signal fanout failed', ['error' => $e->getMessage()]);
        }

        // Increment impression counters for sponsored posts
        $viewedPostIds = collect($validated['events'])
            ->where('event_type', 'view')
            ->pluck('post_id')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        if (!empty($viewedPostIds)) {
            \App\Models\SponsoredPost::whereIn('post_id', $viewedPostIds)
                ->where('status', 'active')
                ->increment('impressions_delivered');
        }

        return response()->json([
            'status' => 'ok',
            'accepted' => $inserted,
            'duplicates' => count($rows) - $inserted,
        ]);
    }
}
