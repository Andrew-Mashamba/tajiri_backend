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
];
