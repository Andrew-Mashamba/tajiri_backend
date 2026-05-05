<?php

return [

    'moderator_user_ids' => array_values(array_filter(array_map('intval', explode(',', env('SHOP_MODERATOR_USER_IDS', ''))))),

    'shipping' => [
        'base_fee_tzs' => (float) env('SHOP_SHIPPING_BASE_TZS', 3500),
        'per_kg_tzs' => (float) env('SHOP_SHIPPING_PER_KG_TZS', 2000),
    ],

    'subscription_plans' => [
        ['id' => 'seller_standard', 'name' => 'Standard seller', 'price_tzs_month' => 0, 'features' => ['basic_listing']],
        ['id' => 'seller_pro', 'name' => 'Pro seller', 'price_tzs_month' => 19900, 'features' => ['analytics', 'priority_support']],
    ],

    'ads' => [
        'default_segments' => [
            ['id' => 'tz_all', 'label' => 'Tanzania — all shoppers'],
            ['id' => 'engaged_buyers', 'label' => 'Engaged marketplace buyers'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Promotional codes (MVP — replace with DB-driven promos when needed)
    |--------------------------------------------------------------------------
    |
    | Keys are uppercase codes. Types:
    | - fixed: discount is a flat TZS amount (capped by order subtotal)
    | - percent: discount is percentage of applicable subtotal, optional max_tzs cap
    |
    */
    'promo_codes' => [
        'WELCOME500' => [
            'type' => 'fixed',
            'amount' => 500,
            'description' => 'Welcome discount 500 TZS',
        ],
        'TAJIRI10' => [
            'type' => 'percent',
            'percent' => 10,
            'max_tzs' => 50000,
            'description' => '10% off (max 50,000 TZS)',
        ],
    ],
];
