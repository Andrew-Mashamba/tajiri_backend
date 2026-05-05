<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->string('key', 50)->primary();
            $table->boolean('enabled')->default(false);
            $table->integer('rollout_pct')->default(0);
            $table->text('description')->nullable();
            $table->timestamp('updated_at')->useCurrent();
        });

        DB::table('feature_flags')->insert([
            ['key' => 'content_engine_search', 'enabled' => false, 'rollout_pct' => 0, 'description' => 'Use v2 search endpoint'],
            ['key' => 'content_engine_feed_for_you', 'enabled' => false, 'rollout_pct' => 0, 'description' => 'Use v2 feed for For You tab'],
            ['key' => 'content_engine_feed_discover', 'enabled' => false, 'rollout_pct' => 0, 'description' => 'Use v2 feed for Discover tab'],
            ['key' => 'content_engine_feed_trending', 'enabled' => false, 'rollout_pct' => 0, 'description' => 'Use v2 feed for Trending tab'],
            ['key' => 'content_engine_feed_nearby', 'enabled' => false, 'rollout_pct' => 0, 'description' => 'Use v2 feed for Nearby tab'],
            ['key' => 'content_engine_ai_digest', 'enabled' => false, 'rollout_pct' => 0, 'description' => 'Show AI trending digest'],
            ['key' => 'content_engine_ai_coaching', 'enabled' => false, 'rollout_pct' => 0, 'description' => 'Generate creator coaching'],
            ['key' => 'content_engine_ai_moderation', 'enabled' => false, 'rollout_pct' => 0, 'description' => 'AI content moderation'],
            ['key' => 'content_engine_query_expansion', 'enabled' => false, 'rollout_pct' => 0, 'description' => 'Claude query expansion on search'],
            ['key' => 'content_engine_dwell_tracking', 'enabled' => false, 'rollout_pct' => 0, 'description' => 'Track dwell time on frontend'],
            ['key' => 'content_engine_more_like_this', 'enabled' => false, 'rollout_pct' => 0, 'description' => 'Show similar content recommendations'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
    }
};
