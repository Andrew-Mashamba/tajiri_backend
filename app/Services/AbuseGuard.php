<?php

namespace App\Services;

use App\Services\Earnings\EarningEventDto;
use Illuminate\Support\Facades\DB;

class AbuseGuard
{
    /**
     * Apply strategy §8 v1 anti-abuse rules + §10.4 daily soft ceiling.
     * Returns the chargeability decision; non-chargeable events are still recorded
     * for audit so anti-abuse changes never require ledger rewrites.
     *
     * @return array{is_chargeable: bool, charge_reason: ?string}
     */
    public static function check(EarningEventDto $dto, array $attribution): array
    {
        // §8 — Self-action exclusion.
        if ($dto->actorUserId && $attribution['target_user_id'] === $dto->actorUserId) {
            return ['is_chargeable' => false, 'charge_reason' => 'self_action'];
        }

        // §8 — Per-viewer view dedupe (1 chargeable view per (viewer, post) per 1h).
        if ($dto->metric === 'view' && $dto->actorUserId && $dto->postId) {
            $exists = DB::table('earning_events')
                ->where('actor_user_id', $dto->actorUserId)
                ->where('post_id', $dto->postId)
                ->where('metric', 'view')
                ->where('is_chargeable', true)
                ->where('occurred_at', '>=', now()->subHour())
                ->exists();
            if ($exists) {
                return ['is_chargeable' => false, 'charge_reason' => 'duplicate_view_within_1h'];
            }
        }

        // §8 — Watch-second cap (≤ video_duration per (viewer, post, day)).
        if ($dto->metric === 'watch_second' && $dto->actorUserId && $dto->postId) {
            $secondsToday = (int) DB::table('earning_events')
                ->where('actor_user_id', $dto->actorUserId)
                ->where('post_id', $dto->postId)
                ->where('metric', 'watch_second')
                ->where('is_chargeable', true)
                ->whereDate('occurred_at', now()->toDateString())
                ->sum('raw_count');
            $videoDuration = (int) ($dto->videoDurationSeconds ?? PHP_INT_MAX);
            if ($secondsToday + $dto->rawCount > $videoDuration) {
                return ['is_chargeable' => false, 'charge_reason' => 'watch_second_cap_exceeded'];
            }
        }

        // §8 — Reaction churn cap (1 credit per (actor, target, metric, day)).
        if (in_array($dto->metric, ['reaction', 'save', 'comment_reaction'], true) && $dto->actorUserId) {
            $exists = DB::table('earning_events')
                ->where('actor_user_id', $dto->actorUserId)
                ->where('target_user_id', $attribution['target_user_id'])
                ->where('metric', $dto->metric)
                ->where('is_chargeable', true)
                ->whereDate('occurred_at', now()->toDateString())
                ->exists();
            if ($exists) {
                return ['is_chargeable' => false, 'charge_reason' => 'reaction_churn'];
            }
        }

        // §8 — Daily per-actor-per-creator cap (50 chargeable engagements/day).
        if ($dto->actorUserId) {
            $count = (int) DB::table('earning_events')
                ->where('actor_user_id', $dto->actorUserId)
                ->where('target_user_id', $attribution['target_user_id'])
                ->where('is_chargeable', true)
                ->whereDate('occurred_at', now()->toDateString())
                ->count();
            if ($count >= 50) {
                return ['is_chargeable' => false, 'charge_reason' => 'daily_actor_creator_cap_50'];
            }
        }

        // §10.4 — Daily per-creator soft ceiling on engagement (TZS 500k).
        if ($dto->stream === 'engagement') {
            $todayCleared = (float) DB::table('earning_events')
                ->where('target_user_id', $attribution['target_user_id'])
                ->where('stream', 'engagement')
                ->where('is_chargeable', true)
                ->whereDate('occurred_at', now()->toDateString())
                ->sum('net_to_creator');
            if ($todayCleared >= config('earnings.daily_soft_cap_tsh', 500_000)) {
                return ['is_chargeable' => false, 'charge_reason' => 'daily_creator_soft_cap_500k'];
            }
        }

        return ['is_chargeable' => true, 'charge_reason' => null];
    }
}
