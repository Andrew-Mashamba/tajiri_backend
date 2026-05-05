<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Shop\ShopCommerceAnalyticsEvent;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommerceAnalyticsController extends Controller
{
    /**
     * POST /shop/analytics/events — batch ingestion.
     */
    public function storeBatch(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'nullable|integer|exists:users,id',
            'events' => 'required|array|min:1|max:200',
            'events.*.name' => 'required|string|max:128',
            'events.*.properties' => 'nullable|array',
            'events.*.occurred_at' => 'nullable|date',
        ]);

        $userId = $request->input('user_id');
        $now = now();
        $inserted = 0;

        foreach ($request->input('events', []) as $row) {
            ShopCommerceAnalyticsEvent::create([
                'user_id' => $userId,
                'event_name' => $row['name'],
                'properties' => $row['properties'] ?? [],
                'occurred_at' => isset($row['occurred_at']) ? Carbon::parse($row['occurred_at']) : $now,
            ]);
            $inserted++;
        }

        return response()->json([
            'success' => true,
            'inserted' => $inserted,
        ], 201);
    }
}
