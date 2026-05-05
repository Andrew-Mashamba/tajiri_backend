# Creators Fund Engine — Deploy Checklist & Rollout

> v1 deployment of the Creators Fund engine per `docs/superpowers/plans/2026-05-03-creators-fund-engine.md` (frontend repo). Tasks 77–84.

## Prerequisites

- [ ] **COA module deployed** — `chart_of_accounts` and `journal_lines` tables present in production. The `CreatorsFundCoaSeeder` will fail loudly if they aren't (this is intentional).
- [ ] **Tajiri Pay deployed** — `wallets` and `wallet_transactions` tables present. `PayoutDisbursementJob` will skip silently if missing, but no real payouts will go out.

## Task 77 — Migration & data backfill plan

**Backfill policy: forward-only.** No historical events are created from existing `posts.*_count` columns. The counters remain on the `posts` table as read-model data; `earning_events` starts fresh from cutover.

Migrations to run in order (all dated `2026_05_03_*`):

1. `2026_05_03_000001_extend_creator_earnings_rates.php`
2. `2026_05_03_000002_create_earning_events_table.php`
3. `2026_05_03_000003_create_creator_tiers_table.php`
4. `2026_05_03_000004_create_creators_fund_periods_table.php`
5. `2026_05_03_000005_create_creators_fund_points_table.php`
6. `2026_05_03_000006_create_earnings_reserve_ledger_table.php`
7. `2026_05_03_000007_create_earnings_coa_accounts.php` *(invokes seeder)*
8. `2026_05_03_000008_create_post_share_attributions_table.php`
9. `2026_05_03_000009_add_origin_post_id_to_user_follows.php`
10. `2026_05_03_000010_add_origin_post_id_to_subscriptions.php`
11. `2026_05_03_000011_create_earnings_disputes_table.php`
12. `2026_05_03_000012_add_disbursed_at_to_earning_events.php`
13. `2026_05_03_000013_add_discovery_mode_to_posts.php`

Seeders to run after migrations:

```bash
php artisan db:seed --class=CreatorsFundCoaSeeder --force
php artisan db:seed --class=CreatorsFundInitialPeriodSeeder --force
```

`creator_tiers` rows are auto-created in Mwanzo state on first call to `CreatorTier::forUser($userId)` — no seed needed.

## Task 83 — Deploy checklist

```bash
# 1) SSH to backend
sshpass -p 'ZimaBlueApps' ssh -o StrictHostKeyChecking=no root@172.240.241.180

# 2) Pull main
cd /var/www/tajiri.zimasystems.com && git pull origin main

# 3) Run migrations
php artisan migrate --force

# 4) Run seeders
php artisan db:seed --class=Database\\Seeders\\CreatorsFundCoaSeeder --force
php artisan db:seed --class=Database\\Seeders\\CreatorsFundInitialPeriodSeeder --force

# 5) Cache config + routes
php artisan config:cache && php artisan route:cache

# 6) Wire scheduled jobs in app/Console/Kernel.php (or routes/console.php):
#    Schedule::job(new \App\Jobs\CreatorsFundPeriodSettlementJob())->weeklyOn(1, '00:00')->timezone('Africa/Nairobi');
#    Schedule::job(new \App\Jobs\SettlementSweepJob())->dailyAt('02:00');
#    Schedule::job(new \App\Jobs\TierReviewJob())->dailyAt('03:30');
#    Schedule::job(new \App\Jobs\MwanzoExpiryJob())->dailyAt('04:00');
#    Schedule::job(new \App\Jobs\PayoutDisbursementJob())->dailyAt('06:00');
#    Schedule::job(new \App\Jobs\TRARemittanceJob())->monthlyOn(5, '08:00');

# 7) Verify scheduler
php artisan schedule:list

# 8) Smoke-test public endpoints
curl https://tajiri.zimasystems.com/api/creators/rate-card | jq .
curl 'https://tajiri.zimasystems.com/api/users/me/earnings?user_id=1' \
    -H 'Authorization: Bearer YOUR_TOKEN' | jq .

# 9) Wire engagement hooks per docs/CREATORS_FUND_HOOK_WIRING.md
#    (manual edits to PostController, CommentController, FollowController,
#    SubscriptionController, LiveStreamController, AdvancedStreamController,
#    ShopOrderController; see the wiring guide for exact patches).

# 10) Build Flutter
cd /Volumes/DATA/PROJECTS/TAJIRI/TAJIRI-FRONTEND
flutter build apk --release
```

## Task 84 — Rollout announcement

After deploy:

- [ ] Add a "What's New" banner in the home feed pointing to `/creator-earnings`.
- [ ] Public rate-card URL: `https://tajiri.zimasystems.com/api/creators/rate-card`.
- [ ] Email announcement to existing creators with their projected earnings under the new model.
- [ ] Update social channels (the platform's own) with the launch message.
- [ ] Monitor `earning_events` table for the first week:
   - Total chargeable events / day
   - Median `gross_credit` per chargeable event
   - Total points accumulated in active period
   - Anti-abuse rejection counts by `charge_reason`
   - Any `is_chargeable=false` clusters that suggest legit traffic is being rejected
- [ ] At first weekly settlement (Monday after deploy), inspect `creators_fund_periods.fund_per_point` and per-creator payouts. If far from the TZS 10,000/week target, adjust `PHASE_1_WEEKLY_FUND_TSH` env var or rate values.

## Test execution

Backend:

```bash
php artisan test tests/Unit/MultiplierEngineTest.php
php artisan test tests/Unit/AbuseGuardTest.php
php artisan test tests/Unit/EarningsEngineTest.php
```

Frontend:

```bash
flutter test test/screens/creator_earnings_models_test.dart
```

## Open risks (per plan §"Open questions / risks")

1. **Rate-card initial values are starting points** — Tune in week 1.
2. **AbuseGuard runs DB queries per event** — High-throughput migration to Redis counters in v3.
3. **Tajiri Pay payout API contract** — Confirm wallet credit triggers MoMo push or requires separate step.
4. **TRA WHT remittance** — v1 produces journal entries; manual remittance from bank using journal totals. v3 wires the TRA digital portal API.
5. **Sock-puppet detection deferred to v2** — Daily caps protect against amateur abuse; sophisticated rings are a known gap.
6. **`journal_lines` schema assumed** — `account_code` / `debit` / `credit` / `description` / `reference_type` / `reference_id`. If actual schema differs, edit insert statements.
7. **`PostController::recordView` auth context** — Anonymous views recorded but not chargeable; confirm whether the view endpoint should be auth-gated.
8. **Concurrent points upsert** — Postgres `ON CONFLICT DO UPDATE` is safe at v1 throughput; migrate to Redis for v2+.

## Hook wiring (manual step for v1)

The 15 engagement hooks (Tasks 26–40) are documented in `docs/CREATORS_FUND_HOOK_WIRING.md`. Each hook is a small append to an existing controller method, wrapped in `try/catch` so an earnings failure can never break the engagement endpoint that triggered it. Apply these patches as part of the deploy or as a follow-up commit immediately after.
