<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Typesense Configuration
    |--------------------------------------------------------------------------
    */
    'typesense' => [
        'host' => env('TYPESENSE_HOST', 'localhost'),
        'port' => env('TYPESENSE_PORT', '8108'),
        'protocol' => env('TYPESENSE_PROTOCOL', 'http'),
        'api_key' => env('TYPESENSE_API_KEY', 'tajiri-typesense-key-2026'),
        'collection' => env('TYPESENSE_COLLECTION', 'content_documents'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Embedding Service Configuration
    |--------------------------------------------------------------------------
    */
    'embedding' => [
        'url' => env('EMBEDDING_SERVICE_URL', 'http://localhost:8200'),
        'timeout' => env('EMBEDDING_TIMEOUT', 10),
        'batch_size' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Claude AI Configuration
    |--------------------------------------------------------------------------
    */
    'claude' => [
        'cli_path' => env('CLAUDE_CLI_PATH', 'claude'),
        'scoring_model' => env('CLAUDE_SCORING_MODEL', 'haiku'),
        'query_model' => env('CLAUDE_QUERY_MODEL', 'haiku'),
        'digest_model' => env('CLAUDE_DIGEST_MODEL', 'sonnet'),
        'coaching_model' => env('CLAUDE_COACHING_MODEL', 'sonnet'),
        'moderation_model' => env('CLAUDE_MODERATION_MODEL', 'sonnet'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scoring Configuration
    |--------------------------------------------------------------------------
    */
    'scoring' => [
        'freshness_half_lives' => [
            'post' => 24,
            'clip' => 48,
            'music' => 168,
            'stream' => 12,
            'event' => 72,
            'product' => 336,
            'gossip_thread' => 24,
            'campaign' => 168,
            'group' => 720,
            'page' => 720,
            'user_profile' => 720,
            'story' => 6,
        ],
        'engagement_normalization_k' => 50,
        'trending_velocity_multiplier' => 20,
        'trending_rising_threshold' => 3,
        'trending_breaking_threshold' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Tier Thresholds
    |--------------------------------------------------------------------------
    */
    'tiers' => [
        'viral' => 85,
        'high' => 60,
        'medium' => 30,
        'low' => 10,
        // Below 10 = blackhole (or spam_score > 7)
    ],

    /*
    |--------------------------------------------------------------------------
    | Signal Weights (engagement value per event type)
    |--------------------------------------------------------------------------
    */
    'signal_weights' => [
        'view_short' => 0.05,     // < 2s dwell
        'view_glance' => 0.1,     // 2-5s dwell
        'view_partial' => 0.3,    // 5-15s dwell
        'view_deep' => 0.5,       // 15s+ dwell
        'like' => 1.0,
        'save' => 1.8,
        'comment' => 2.0,
        'share' => 2.5,
        'reply' => 3.0,
        'follow' => 2.0,          // follow after viewing
        'scroll_past' => -0.2,    // < 0.5s
        'not_interested' => -5.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Signal Decay Windows (weight multipliers by age)
    |--------------------------------------------------------------------------
    */
    'signal_decay' => [
        'hot_hours' => 1,         // 1.0x weight
        'warm_hours' => 24,       // 0.5x weight
        'cool_hours' => 168,      // 0.2x weight (7 days)
        'cold_hours' => 720,      // 0.05x weight (30 days)
    ],

    /*
    |--------------------------------------------------------------------------
    | Anti-Gaming Rules
    |--------------------------------------------------------------------------
    */
    'anti_gaming' => [
        'per_user_cap_per_hour' => 1,           // max 1 signal per type per doc per hour
        'velocity_fraud_threshold' => 100,       // >100 in 5min from new accounts = fraud
        'new_account_days' => 7,                 // accounts < 7 days old
        'fraud_trending_cap' => 50,              // cap trending_score on fraud flag
        'ip_cluster_threshold' => 10,            // >10 same doc same IP in 5min
        'ip_cluster_count_limit' => 3,           // only first 3 count
        'zero_social_weight' => 0.1,             // 0 friends + 0 posts → 0.1x signal
    ],

    /*
    |--------------------------------------------------------------------------
    | Score Sync Configuration
    |--------------------------------------------------------------------------
    */
    'score_sync' => [
        'dirty_set_key' => 'scores:dirty',
        'sync_interval_seconds' => 30,
        'batch_size' => 200,
    ],


    // ============================================================
    // Serving Pipeline (Phase 4)
    // ============================================================

    'serving' => [
        'candidate_limits' => [
            'typesense' => 200,
            'pgvector' => 100,
            'trending' => 50,
            'personal' => 100,
            'social' => 100,
        ],

        'feed_sources' => [
            'for_you' => [
                'pgvector' => 100, 'trending' => 50, 'personal' => 100, 'social' => 50,
            ],
            'friends' => [
                'social' => 500,
            ],
            'discover' => [
                'pgvector' => 100, 'trending' => 100,
            ],
            'trending' => [
                'trending' => 200,
            ],
            'nearby' => [
                'trending' => 100, 'social' => 50,
            ],
            'shorts' => [
                'typesense' => 100, 'trending' => 50, 'personal' => 50,
            ],
            'audio' => [
                'typesense' => 100, 'trending' => 50, 'personal' => 50,
            ],
            'search' => [
                'typesense' => 200, 'pgvector' => 100, 'trending' => 30,
            ],
        ],

        'personalized_scoring' => [
            'creator_affinity_max' => 15,
            'category_affinity_max' => 10,
            'hashtag_affinity_max' => 8,
            'media_preference_max' => 5,
            'social_proximity_friend' => 20,
            'social_proximity_fof' => 8,
            'regional_same_region' => 5,
            'regional_same_district' => 3,
        ],

        'reranker' => [
            'max_consecutive_same_creator' => 2,
            'max_consecutive_same_type' => 4,
            'freshness_1h_boost' => 1.2,
            'freshness_15min_boost' => 1.4,
            'exploration_pct' => 0.10,
            'exploration_pct_new_user' => 0.30,
            'new_user_days' => 7,
            'anti_bubble_pct' => 0.05,
            'streak_bonus' => 1.1,
            // Sponsored insertion deferred to Phase 5
        ],

        'cache' => [
            'feed_ttl' => 60,
            'search_ttl' => 300,
            'trending_ttl' => 120,
        ],

        'per_page_default' => 20,
        'per_page_max' => 50,
    ],
];
