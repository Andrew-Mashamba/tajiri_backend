<?php

return [
    // §3.1 — applied to view + watch_second metrics only.
    'watch_completion' => [
        ['min_pct' => 0.00, 'max_pct' => 0.25, 'mult' => 0.5],
        ['min_pct' => 0.25, 'max_pct' => 0.50, 'mult' => 1.0],
        ['min_pct' => 0.50, 'max_pct' => 0.70, 'mult' => 1.5],
        ['min_pct' => 0.70, 'max_pct' => 0.90, 'mult' => 2.0],
        ['min_pct' => 0.90, 'max_pct' => 1.01, 'mult' => 2.5],
    ],
    // §3.3 — originality.
    'originality' => [
        'original'               => 1.0,
        'derivative_substantial' => 0.7,
        'derivative_minimal'     => 0.4,
        'reused'                 => 0.0,
    ],
    // §3.2 — Mwanzo boost.
    'mwanzo_boost'        => 2.0,
    'mwanzo_window_days'  => 30,
    // §3.4 — streak.
    'streak_bonus'         => 1.10,
    'streak_min_days_in_7' => 5,
    // §3.5 — discovery mode.
    'discovery_mode'         => 0.70,
    'discovery_window_days'  => 30,
    // §4 — tier boost.
    'tier_boost' => [
        'mwanzo'   => 1.0,
        'standard' => 1.0,
        'verified' => 1.0,
        'partner'  => 1.05,
    ],
];
