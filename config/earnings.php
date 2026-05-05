<?php

// Strategy §1.2 + §5 + §6 + §7 + §10 — global earnings config.
return [
    // Active phase. Toggle to 'phase_2' when §1.3 transition criteria met.
    'phase' => env('EARNINGS_PHASE', 'phase_1'),

    // Phase 1 commitment (creator-acquisition spend).
    'phase_1_weekly_fund_tsh' => env('PHASE_1_WEEKLY_FUND_TSH', 50_000_000),

    // Settlement window.
    'settlement_window_days'    => 30,
    'fan_funding_window_days'   => 45,

    // §6.2 minimum payout threshold.
    'min_payout_tsh' => 5000,

    // §7.1 TZ Section 83B WHT rate.
    'wht_rate' => 0.05,

    // §10.4 daily per-creator soft ceiling on engagement pool.
    'daily_soft_cap_tsh' => 500_000,
];
