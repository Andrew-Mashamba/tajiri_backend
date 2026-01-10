<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hybrid Post System Enhancement
 *
 * Inspired by:
 * - TikTok: Watch time as primary signal, engagement scoring
 * - Instagram: Two-feed system (Following + Discover), sends/shares weighted
 * - YouTube: Views as metric, content categorization
 * - Twitter: Replies > Retweets > Likes weighting, freshness factor
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Enhance posts table with engagement metrics
        Schema::table('posts', function (Blueprint $table) {
            // Engagement metrics (TikTok/Instagram inspired)
            $table->unsignedBigInteger('views_count')->default(0)->after('shares_count');
            $table->unsignedBigInteger('impressions_count')->default(0)->after('views_count');
            $table->unsignedBigInteger('watch_time_seconds')->default(0)->after('impressions_count'); // Total watch time for video posts
            $table->unsignedBigInteger('saves_count')->default(0)->after('watch_time_seconds'); // Bookmarks/saves
            $table->unsignedBigInteger('replies_count')->default(0)->after('saves_count'); // Direct replies (Twitter-style)

            // Engagement score (calculated field for ranking)
            $table->decimal('engagement_score', 10, 4)->default(0)->after('replies_count');
            $table->decimal('trending_score', 10, 4)->default(0)->after('engagement_score');

            // Content classification (Instagram micro-niche style)
            $table->string('content_category')->nullable()->after('trending_score'); // AI-detected category
            $table->json('content_tags')->nullable()->after('content_category'); // Auto-extracted tags
            $table->string('language_code', 5)->default('sw')->after('content_tags'); // Content language

            // Video-specific (TikTok/Shorts inspired)
            $table->boolean('is_short_video')->default(false)->after('language_code'); // <= 60 seconds
            $table->boolean('is_featured')->default(false)->after('is_short_video'); // Editor's pick
            $table->boolean('is_viral')->default(false)->after('is_featured'); // Trending threshold reached

            // Reach tracking (Instagram-style)
            $table->unsignedBigInteger('reach_followers')->default(0)->after('is_viral'); // Reached followers
            $table->unsignedBigInteger('reach_non_followers')->default(0)->after('reach_followers'); // Reached via discover

            // Geolocation (for nearby feed)
            $table->decimal('latitude', 10, 8)->nullable()->after('location_name');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
            $table->foreignId('region_id')->nullable()->after('longitude');

            // Scheduling
            $table->timestamp('scheduled_at')->nullable()->after('is_pinned');
            $table->boolean('is_draft')->default(false)->after('scheduled_at');

            // Indexes for feed algorithms
            $table->index('engagement_score');
            $table->index('trending_score');
            $table->index('is_short_video');
            $table->index('is_featured');
            $table->index('content_category');
            $table->index(['created_at', 'engagement_score']);
            $table->index(['privacy', 'created_at', 'trending_score']);
        });

        // 2. Create hashtags table (normalized for trending)
        Schema::create('hashtags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique(); // Without # symbol
            $table->string('name_normalized', 100)->unique(); // Lowercase for search
            $table->unsignedBigInteger('posts_count')->default(0);
            $table->unsignedBigInteger('usage_count_24h')->default(0); // For trending
            $table->unsignedBigInteger('usage_count_7d')->default(0);
            $table->boolean('is_trending')->default(false);
            $table->boolean('is_blocked')->default(false); // For moderation
            $table->string('category')->nullable(); // Topic category
            $table->timestamps();

            $table->index('is_trending');
            $table->index('usage_count_24h');
            $table->index('posts_count');
        });

        // 3. Pivot table for posts and hashtags
        Schema::create('post_hashtags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->onDelete('cascade');
            $table->foreignId('hashtag_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['post_id', 'hashtag_id']);
            $table->index('hashtag_id');
        });

        // 4. Post views/impressions tracking (for analytics)
        Schema::create('post_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('user_profiles')->onDelete('set null');
            $table->string('session_id', 64)->nullable(); // For anonymous tracking
            $table->unsignedInteger('watch_time_seconds')->default(0); // Video watch time
            $table->decimal('watch_percentage', 5, 2)->default(0); // % of video watched
            $table->boolean('is_complete_view')->default(false); // Watched to end
            $table->boolean('is_replay')->default(false); // Watched again
            $table->string('source')->default('feed'); // feed, profile, discover, search, share
            $table->string('device_type')->nullable(); // mobile, tablet, desktop
            $table->timestamp('created_at');

            $table->index(['post_id', 'created_at']);
            $table->index(['user_id', 'post_id']);
            $table->index('source');
        });

        // 5. Post saves/bookmarks
        Schema::create('post_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained('user_profiles')->onDelete('cascade');
            $table->foreignId('collection_id')->nullable(); // For organizing saves
            $table->timestamps();

            $table->unique(['post_id', 'user_id']);
            $table->index('user_id');
        });

        // 6. Save collections
        Schema::create('save_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user_profiles')->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_private')->default(true);
            $table->unsignedInteger('posts_count')->default(0);
            $table->timestamps();

            $table->index('user_id');
        });

        // 7. User interests (for personalized feed)
        Schema::create('user_interests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user_profiles')->onDelete('cascade');
            $table->string('interest_type'); // hashtag, category, creator
            $table->string('interest_value'); // The hashtag/category/creator_id
            $table->decimal('weight', 5, 4)->default(1.0); // Interest strength (0-1)
            $table->unsignedInteger('interaction_count')->default(0);
            $table->timestamp('last_interaction_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'interest_type', 'interest_value']);
            $table->index(['user_id', 'weight']);
        });

        // 8. Feed cache for performance
        Schema::create('feed_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user_profiles')->onDelete('cascade');
            $table->string('feed_type'); // for_you, following, trending, discover
            $table->json('post_ids'); // Cached post IDs
            $table->unsignedInteger('page')->default(1);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['user_id', 'feed_type', 'page']);
            $table->index('expires_at');
        });

        // 9. Add foreign key for region
        Schema::table('posts', function (Blueprint $table) {
            $table->foreign('region_id')->references('id')->on('regions')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropForeign(['region_id']);

            $table->dropIndex(['engagement_score']);
            $table->dropIndex(['trending_score']);
            $table->dropIndex(['is_short_video']);
            $table->dropIndex(['is_featured']);
            $table->dropIndex(['content_category']);
            $table->dropIndex(['created_at', 'engagement_score']);
            $table->dropIndex(['privacy', 'created_at', 'trending_score']);

            $table->dropColumn([
                'views_count', 'impressions_count', 'watch_time_seconds', 'saves_count',
                'replies_count', 'engagement_score', 'trending_score', 'content_category',
                'content_tags', 'language_code', 'is_short_video', 'is_featured', 'is_viral',
                'reach_followers', 'reach_non_followers', 'latitude', 'longitude', 'region_id',
                'scheduled_at', 'is_draft'
            ]);
        });

        Schema::dropIfExists('feed_cache');
        Schema::dropIfExists('user_interests');
        Schema::dropIfExists('save_collections');
        Schema::dropIfExists('post_saves');
        Schema::dropIfExists('post_views');
        Schema::dropIfExists('post_hashtags');
        Schema::dropIfExists('hashtags');
    }
};
