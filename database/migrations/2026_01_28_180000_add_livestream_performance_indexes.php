<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add indexes for livestreaming performance optimization.
     */
    public function up(): void
    {
        // Live streams indexes
        Schema::table('live_streams', function (Blueprint $table) {
            // Composite index for listing active streams (most common query)
            $table->index(['status', 'created_at'], 'idx_streams_status_created');

            // Index for user's streams
            $table->index(['user_id', 'status'], 'idx_streams_user_status');

            // Index for scheduled streams lookup
            $table->index(['status', 'scheduled_at'], 'idx_streams_scheduled');

            // Index for stream key lookup (RTMP auth)
            $table->index('stream_key', 'idx_streams_stream_key');
        });

        // Stream viewers indexes
        Schema::table('stream_viewers', function (Blueprint $table) {
            // Composite index for counting current viewers
            $table->index(['stream_id', 'is_currently_watching'], 'idx_viewers_stream_watching');

            // Index for user lookup
            $table->index(['user_id', 'stream_id'], 'idx_viewers_user_stream');

            // Index for active viewers with joined_at for sorting
            $table->index(['stream_id', 'is_currently_watching', 'joined_at'], 'idx_viewers_stream_active_joined');
        });

        // Stream comments indexes
        Schema::table('stream_comments', function (Blueprint $table) {
            // Composite index for fetching comments (stream_id + created_at for pagination)
            $table->index(['stream_id', 'created_at'], 'idx_comments_stream_created');

            // Index for pinned comments
            $table->index(['stream_id', 'is_pinned'], 'idx_comments_pinned');

            // Index for user's comments
            $table->index(['user_id', 'stream_id'], 'idx_comments_user_stream');
        });

        // Stream gifts indexes
        Schema::table('stream_gifts', function (Blueprint $table) {
            // Composite index for stream gifts with time
            $table->index(['stream_id', 'created_at'], 'idx_gifts_stream_created');

            // Index for user's sent gifts
            $table->index(['sender_id', 'created_at'], 'idx_gifts_sender_created');

            // Index for gift analytics
            $table->index(['stream_id', 'gift_id'], 'idx_gifts_stream_gift');
        });

        // Stream reactions indexes (if table exists)
        if (Schema::hasTable('stream_reactions')) {
            Schema::table('stream_reactions', function (Blueprint $table) {
                $table->index(['stream_id', 'created_at'], 'idx_reactions_stream_created');
                $table->index(['stream_id', 'reaction_type'], 'idx_reactions_stream_type');
            });
        }

        // Stream polls indexes (if table exists)
        if (Schema::hasTable('stream_polls')) {
            Schema::table('stream_polls', function (Blueprint $table) {
                $table->index(['stream_id', 'is_closed'], 'idx_polls_stream_closed');
            });
        }

        // Stream poll votes indexes (if table exists)
        if (Schema::hasTable('stream_poll_votes')) {
            Schema::table('stream_poll_votes', function (Blueprint $table) {
                $table->index(['poll_id', 'option_id'], 'idx_poll_votes_poll_option');
                $table->unique(['poll_id', 'user_id'], 'idx_poll_votes_unique_user');
            });
        }

        // Stream questions indexes (if table exists)
        if (Schema::hasTable('stream_questions')) {
            Schema::table('stream_questions', function (Blueprint $table) {
                $table->index(['stream_id', 'is_answered', 'upvotes'], 'idx_questions_stream_answered_upvotes');
            });
        }

        // Stream super chats indexes (if table exists)
        if (Schema::hasTable('stream_super_chats')) {
            Schema::table('stream_super_chats', function (Blueprint $table) {
                $table->index(['stream_id', 'tier', 'created_at'], 'idx_super_chats_stream_tier_created');
            });
        }

        // Stream battles indexes (if table exists)
        if (Schema::hasTable('stream_battles')) {
            Schema::table('stream_battles', function (Blueprint $table) {
                $table->index(['stream_id_1', 'status'], 'idx_battles_stream1_status');
                $table->index(['stream_id_2', 'status'], 'idx_battles_stream2_status');
            });
        }

        // Stream analytics indexes (if table exists)
        if (Schema::hasTable('stream_analytics')) {
            Schema::table('stream_analytics', function (Blueprint $table) {
                $table->index(['stream_id', 'timestamp'], 'idx_analytics_stream_timestamp');
            });
        }

        // Stream cohosts indexes
        if (Schema::hasTable('stream_cohosts')) {
            Schema::table('stream_cohosts', function (Blueprint $table) {
                $table->index(['stream_id', 'status'], 'idx_cohosts_stream_status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Live streams indexes
        Schema::table('live_streams', function (Blueprint $table) {
            $table->dropIndex('idx_streams_status_created');
            $table->dropIndex('idx_streams_user_status');
            $table->dropIndex('idx_streams_scheduled');
            $table->dropIndex('idx_streams_stream_key');
        });

        // Stream viewers indexes
        Schema::table('stream_viewers', function (Blueprint $table) {
            $table->dropIndex('idx_viewers_stream_watching');
            $table->dropIndex('idx_viewers_user_stream');
            $table->dropIndex('idx_viewers_stream_active_joined');
        });

        // Stream comments indexes
        Schema::table('stream_comments', function (Blueprint $table) {
            $table->dropIndex('idx_comments_stream_created');
            $table->dropIndex('idx_comments_pinned');
            $table->dropIndex('idx_comments_user_stream');
        });

        // Stream gifts indexes
        Schema::table('stream_gifts', function (Blueprint $table) {
            $table->dropIndex('idx_gifts_stream_created');
            $table->dropIndex('idx_gifts_sender_created');
            $table->dropIndex('idx_gifts_stream_gift');
        });

        // Stream reactions indexes
        if (Schema::hasTable('stream_reactions')) {
            Schema::table('stream_reactions', function (Blueprint $table) {
                $table->dropIndex('idx_reactions_stream_created');
                $table->dropIndex('idx_reactions_stream_type');
            });
        }

        // Stream polls indexes
        if (Schema::hasTable('stream_polls')) {
            Schema::table('stream_polls', function (Blueprint $table) {
                $table->dropIndex('idx_polls_stream_closed');
            });
        }

        // Stream poll votes indexes
        if (Schema::hasTable('stream_poll_votes')) {
            Schema::table('stream_poll_votes', function (Blueprint $table) {
                $table->dropIndex('idx_poll_votes_poll_option');
                $table->dropIndex('idx_poll_votes_unique_user');
            });
        }

        // Stream questions indexes
        if (Schema::hasTable('stream_questions')) {
            Schema::table('stream_questions', function (Blueprint $table) {
                $table->dropIndex('idx_questions_stream_answered_upvotes');
            });
        }

        // Stream super chats indexes
        if (Schema::hasTable('stream_super_chats')) {
            Schema::table('stream_super_chats', function (Blueprint $table) {
                $table->dropIndex('idx_super_chats_stream_tier_created');
            });
        }

        // Stream battles indexes
        if (Schema::hasTable('stream_battles')) {
            Schema::table('stream_battles', function (Blueprint $table) {
                $table->dropIndex('idx_battles_stream1_status');
                $table->dropIndex('idx_battles_stream2_status');
            });
        }

        // Stream analytics indexes
        if (Schema::hasTable('stream_analytics')) {
            Schema::table('stream_analytics', function (Blueprint $table) {
                $table->dropIndex('idx_analytics_stream_timestamp');
            });
        }

        // Stream cohosts indexes
        if (Schema::hasTable('stream_cohosts')) {
            Schema::table('stream_cohosts', function (Blueprint $table) {
                $table->dropIndex('idx_cohosts_stream_status');
            });
        }
    }
};
