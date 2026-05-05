<?php

namespace App\Services;

use App\Models\CreatorsFundPeriod;
use App\Models\CreatorTier;
use App\Models\EarningEvent;
use App\Services\Earnings\EarningEventDto;
use App\Services\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Central earnings entrypoint. Every engagement event hook calls
 * EarningsEngine::recordEvent($dto). The engine handles attribution
 * (B+C rules from strategy §2), abuse-guard, rate lookup, multipliers,
 * idempotent insert into earning_events, journal_lines pending entry,
 * and points accrual on the active fund period.
 *
 * Idempotency: same (source_type, source_id, actor, target_user_id,
 * actor_role, metric) tuple → same event_uid → returns existing row.
 */
class EarningsEngine
{
    /**
     * Single entrypoint. Returns the list of EarningEvent rows written
     * (one per attribution tuple — e.g. a comment_reaction may credit
     * comment_author + post_author/host).
     *
     * @return array<EarningEvent|null>
     */
    public static function recordEvent(EarningEventDto $dto): array
    {
        $rows = [];
        foreach (self::applyAttribution($dto) as $attr) {
            $rows[] = self::recordOneCredit($dto, $attr);
        }
        return $rows;
    }

    /** Build deterministic event_uid for idempotency. */
    public static function makeEventUid(EarningEventDto $dto, array $attribution): string
    {
        return hash('sha256', implode('|', [
            $dto->sourceType,
            (string) $dto->sourceId,
            $dto->actorUserId !== null ? (string) $dto->actorUserId : 'anonymous',
            (string) $attribution['target_user_id'],
            $attribution['actor_role'],
            $dto->metric,
        ]));
    }

    /**
     * Apply strategy §2.1 + §2.2 attribution table. Returns one or more
     * (target_user_id, actor_role) tuples — one row per earner.
     */
    public static function applyAttribution(EarningEventDto $dto): array
    {
        $tuples = [];
        $authorId = $dto->postAuthorId;

        // 1. Primary author always gets credit.
        if ($authorId) {
            $tuples[] = ['target_user_id' => $authorId, 'actor_role' => 'author'];
        }

        // 2. Sharer secondary credit.
        if ($dto->sharerUserId && in_array($dto->metric, ['view', 'reaction', 'share', 'save', 'comment'], true)) {
            $tuples[] = ['target_user_id' => $dto->sharerUserId, 'actor_role' => 'sharer'];
        }

        // 3. Comment-author / reply-author credits.
        if ($dto->metric === 'reply' && $dto->commentAuthorId) {
            $tuples[] = ['target_user_id' => $dto->commentAuthorId, 'actor_role' => 'comment_author'];
        }
        if ($dto->metric === 'comment_reaction') {
            if ($dto->commentAuthorId) {
                $tuples[] = ['target_user_id' => $dto->commentAuthorId, 'actor_role' => 'comment_author'];
            }
            if ($authorId && $authorId !== $dto->commentAuthorId) {
                $tuples[] = ['target_user_id' => $authorId, 'actor_role' => 'host'];
            }
        }

        // 4. Derivative royalty — original creator earns when stitch/quote/reply post is engaged.
        if ($dto->metric === 'derivative_royalty' && $dto->originalCreatorId) {
            $tuples = [['target_user_id' => $dto->originalCreatorId, 'actor_role' => 'original_creator_royalty']];
        }

        // 5. Self-action exclusion.
        if ($dto->actorUserId) {
            $tuples = array_values(array_filter(
                $tuples,
                fn ($t) => $t['target_user_id'] !== $dto->actorUserId
            ));
        }

        // 6. Dedupe.
        $seen = [];
        return array_values(array_filter($tuples, function ($t) use (&$seen) {
            $k = $t['target_user_id'] . '|' . $t['actor_role'];
            if (isset($seen[$k])) {
                return false;
            }
            $seen[$k] = true;
            return true;
        }));
    }

    /**
     * Delegate anti-abuse rules to AbuseGuard (strategy §8 + §10.4).
     *
     * @return array{is_chargeable: bool, charge_reason: ?string}
     */
    public static function computeChargeable(EarningEventDto $dto, array $attribution): array
    {
        return AbuseGuard::check($dto, $attribution);
    }

    /**
     * Persist a single attribution credit. Idempotent on event_uid.
     * Inserts a journal_lines pending entry (if the table exists) and
     * accrues points to the active fund period (engagement stream only).
     */
    public static function recordOneCredit(EarningEventDto $dto, array $attribution): ?EarningEvent
    {
        $eventUid = self::makeEventUid($dto, $attribution);

        // Idempotency.
        $existing = EarningEvent::where('event_uid', $eventUid)->first();
        if ($existing) {
            return $existing;
        }

        $tier = CreatorTier::forUser($attribution['target_user_id']);
        if ($tier->monetization_paused) {
            return self::insertNonChargeable($dto, $attribution, $eventUid, 'creator_inactive_paused');
        }

        $rate = CreatorEarningsRateRegistry::rateFor(
            $dto->metric,
            $attribution['actor_role'],
            $dto->stream,
            $tier->tier
        );
        if (!$rate) {
            return self::insertNonChargeable($dto, $attribution, $eventUid, 'no_rate_for_tier_or_role');
        }

        $check = self::computeChargeable($dto, $attribution);
        if (!$check['is_chargeable']) {
            return self::insertNonChargeable($dto, $attribution, $eventUid, $check['charge_reason']);
        }

        $multipliers = MultiplierEngine::compute([
            'target_user_id'        => $attribution['target_user_id'],
            'metric'                => $dto->metric,
            'stream'                => $dto->stream,
            'post_id'               => $dto->postId,
            'watch_completion_pct'  => $dto->watchCompletionPct,
            'originality_flag'      => $dto->originalityFlag,
            'discovery_mode_active' => $dto->discoveryModeActive,
        ]);
        $combinedMult = MultiplierEngine::combined($multipliers);

        $rawCount = max(1, $dto->rawCount);
        $gross = round($rate['rate'] * $rawCount * $combinedMult, 2);
        if ($rate['max_cap_tsh'] !== null) {
            $gross = min($gross, (float) $rate['max_cap_tsh'] * $rawCount);
        }

        // Per-stream platform take. WHT only at clear-time.
        $platformTake = self::platformTakeFor($dto->stream, $gross);
        $netToCreator = max(0.0, round($gross - $platformTake, 2));

        return DB::transaction(function () use ($dto, $attribution, $eventUid, $rate, $multipliers, $rawCount, $gross, $platformTake, $netToCreator) {
            $event = EarningEvent::create([
                'event_uid'         => $eventUid,
                'occurred_at'       => $dto->occurredAt ?? now(),
                'post_id'           => $dto->postId,
                'comment_id'        => $dto->commentId,
                'source_type'       => $dto->sourceType,
                'source_id'         => $dto->sourceId,
                'actor_user_id'     => $dto->actorUserId,
                'target_user_id'    => $attribution['target_user_id'],
                'actor_role'        => $attribution['actor_role'],
                'stream'            => $dto->stream,
                'metric'            => $dto->metric,
                'raw_count'         => $rawCount,
                'rate_tsh'          => $rate['rate'],
                'multipliers'       => $multipliers,
                'gross_credit'      => $gross,
                'platform_take'     => $platformTake,
                'tra_wht_held'      => 0,
                'net_to_creator'    => $netToCreator,
                'is_chargeable'     => true,
                'funding_source'    => $dto->fundingSource ?? 'treasury',
                'settlement_status' => 'pending',
            ]);

            // §5.1 — for pass-through streams (fan_funding, marketplace, brand_deal,
            // live_gifts) we book the creator-credit liability immediately. Engagement
            // events do NOT post per-event entries — the period-settlement job posts
            // a single batched Dr. 5710 / Cr. 2705 → Cr. 2710 entry at period close.
            if (Schema::hasTable('journal_entries') && $dto->stream !== 'engagement' && $netToCreator > 0) {
                $creditCode = match ($dto->stream) {
                    'fan_funding' => '2711',
                    'marketplace' => '2712',
                    'brand_deal'  => '2713',
                    'live_gifts'  => '2714',
                    default       => '2710',
                };
                // Pass-through streams: Dr. (asset / receivable side handled by their
                // own controller, e.g. WalletController for tips) → Cr. pending creator
                // earnings. Here we record the creator-credit half only; the funding
                // side is booked by the originating controller against `wallet_transactions`.
                $debitCode = match ($dto->stream) {
                    'fan_funding' => '4720',  // pass-through: revenue take recognized against creator credit
                    'marketplace' => '4730',
                    'brand_deal'  => '4740',
                    'live_gifts'  => '4750',
                    default       => '5710',
                };
                try {
                    $journalId = LedgerService::post(
                        debitCode:   $debitCode,
                        creditCode:  $creditCode,
                        amount:      $netToCreator,
                        sourceType:  'earning_event',
                        sourceId:    (int) $event->id,
                        description: "Earning credit ({$dto->stream} {$dto->metric}) — event {$event->id}"
                    );
                    $event->update(['journal_line_pending_id' => $journalId]);
                } catch (\Throwable $e) {
                    // Non-fatal — event row already exists; we'll reconcile via re-runner.
                }
            }

            self::accruePoints($event);

            return $event;
        });
    }

    /**
     * Increment the creator's points on the active fund period.
     * Engagement-stream only — pass-through streams settle in real time
     * outside the fund.
     */
    public static function accruePoints(EarningEvent $event): void
    {
        if ($event->stream !== 'engagement' || !$event->is_chargeable) {
            return;
        }

        $period = CreatorsFundPeriod::currentOpen() ?? CreatorsFundPeriod::openNextPeriod('phase_1');

        // Atomic upsert: insert if missing, increment if present.
        DB::statement('
            INSERT INTO creators_fund_points
                (period_id, user_id, points, events_count, last_event_at, created_at, updated_at)
            VALUES (?, ?, ?, 1, ?, now(), now())
            ON CONFLICT (period_id, user_id) DO UPDATE SET
                points        = creators_fund_points.points + EXCLUDED.points,
                events_count  = creators_fund_points.events_count + 1,
                last_event_at = EXCLUDED.last_event_at,
                updated_at    = now()
        ', [
            $period->id,
            $event->target_user_id,
            $event->gross_credit,
            $event->occurred_at,
        ]);
    }

    private static function insertNonChargeable(EarningEventDto $dto, array $attribution, string $uid, ?string $reason): EarningEvent
    {
        return EarningEvent::create([
            'event_uid'         => $uid,
            'occurred_at'       => $dto->occurredAt ?? now(),
            'post_id'           => $dto->postId,
            'comment_id'        => $dto->commentId,
            'source_type'       => $dto->sourceType,
            'source_id'         => $dto->sourceId,
            'actor_user_id'     => $dto->actorUserId,
            'target_user_id'    => $attribution['target_user_id'],
            'actor_role'        => $attribution['actor_role'],
            'stream'            => $dto->stream,
            'metric'            => $dto->metric,
            'raw_count'         => max(1, $dto->rawCount),
            'rate_tsh'          => 0,
            'multipliers'       => [],
            'gross_credit'      => 0,
            'platform_take'     => 0,
            'tra_wht_held'      => 0,
            'net_to_creator'    => 0,
            'is_chargeable'     => false,
            'charge_reason'     => $reason,
            'settlement_status' => 'pending',
        ]);
    }

    /**
     * §1.1 — for engagement, the take is captured at fund-distribution time, not per event.
     * For pass-through streams, derive the platform take from the gross-up of the published creator share.
     */
    private static function platformTakeFor(string $stream, float $gross): float
    {
        return match ($stream) {
            'engagement'  => 0,                                    // fund is fully distributed; no per-event take
            'fan_funding' => round($gross / 0.95 * 0.05, 2),       // 5% take on a 95% creator share
            'marketplace' => 0,                                    // ShopOrderController handles marketplace cuts
            'brand_deal'  => round($gross / 0.90 * 0.10, 2),       // 10% take on a 90% creator share
            'live_gifts'  => round($gross / 0.90 * 0.10, 2),       // 10% take on a 90% creator share
            'affiliate'   => 0,
            default       => 0,
        };
    }
}
