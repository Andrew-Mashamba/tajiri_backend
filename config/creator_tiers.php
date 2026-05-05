<?php

// Strategy §4 — tier gates + per-tier feature flags. Tweakable without
// code change, still 30-day-notice protected per §11.4.
return [
    'mwanzo' => [
        'rank'  => 0,
        'gates' => [
            'followers'   => 0,
            'days_active' => 0,
        ],
        'features' => [
            'engagement_pool'        => true,
            'fan_funding'            => true,
            'marketplace'            => true,
            'live_gifts'             => false,
            'brand_deal_marketplace' => false,
            'discovery_mode'         => false,
        ],
        'mwanzo_window_days' => 30,
        'mwanzo_boost_mult'  => 2.0,
    ],
    'standard' => [
        'rank'  => 1,
        'gates' => [
            'followers'   => 100,
            'days_active' => 30,
            'max_strikes' => 0,
        ],
        'features' => [
            'live_gifts'     => true,
            'discovery_mode' => true,
        ],
    ],
    'verified' => [
        'rank'  => 2,
        'gates' => [
            'followers'        => 1000,
            'views_30d'        => 50_000,
            'is_id_verified'   => true,
            'max_strikes_90d'  => 0,
        ],
        'features' => [
            'brand_deal_marketplace' => true,
        ],
    ],
    'partner' => [
        'rank'  => 3,
        'gates' => [
            'followers'              => 10_000,
            'views_30d'              => 500_000,
            'days_as_verified'       => 90,
            'manual_review_required' => true,
        ],
        'features' => [
            'engagement_split_75_25'    => true,
            'live_gifts_split_92_5_7_5' => true,
            'brand_deal_split_92_5_7_5' => true,
        ],
    ],
];
