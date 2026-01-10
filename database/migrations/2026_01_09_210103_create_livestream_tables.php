<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Live streams
        Schema::create('live_streams', function (Blueprint $table) {
            $table->id();
            $table->string('stream_key')->unique();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('category')->nullable();
            $table->json('tags')->nullable();
            $table->enum('status', ['scheduled', 'live', 'ended', 'cancelled'])->default('scheduled');
            $table->enum('privacy', ['public', 'friends', 'private'])->default('public');
            $table->string('stream_url')->nullable();
            $table->string('playback_url')->nullable();
            $table->string('recording_path')->nullable();
            $table->boolean('is_recorded')->default(true);
            $table->boolean('allow_comments')->default(true);
            $table->boolean('allow_gifts')->default(true);
            $table->integer('viewers_count')->default(0);
            $table->integer('peak_viewers')->default(0);
            $table->integer('total_viewers')->default(0);
            $table->integer('likes_count')->default(0);
            $table->integer('comments_count')->default(0);
            $table->integer('gifts_count')->default(0);
            $table->integer('shares_count')->default(0);
            $table->decimal('gifts_value', 12, 2)->default(0);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->integer('duration')->nullable(); // seconds
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'started_at']);
        });

        // Stream viewers
        Schema::create('stream_viewers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stream_id')->constrained('live_streams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->integer('watch_duration')->default(0);
            $table->timestamps();

            $table->index(['stream_id', 'user_id']);
        });

        // Stream comments
        Schema::create('stream_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stream_id')->constrained('live_streams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->text('content');
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_highlighted')->default(false);
            $table->timestamps();

            $table->index(['stream_id', 'created_at']);
        });

        // Stream likes
        Schema::create('stream_likes', function (Blueprint $table) {
            $table->foreignId('stream_id')->constrained('live_streams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['stream_id', 'user_id']);
        });

        // Virtual gifts
        Schema::create('virtual_gifts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('icon_path');
            $table->string('animation_path')->nullable();
            $table->decimal('price', 10, 2);
            $table->decimal('creator_share', 5, 2)->default(70.00); // percentage
            $table->boolean('is_active')->default(true);
            $table->integer('order')->default(0);
            $table->timestamps();
        });

        // Sent gifts
        Schema::create('stream_gifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stream_id')->constrained('live_streams')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->foreignId('gift_id')->constrained('virtual_gifts')->cascadeOnDelete();
            $table->integer('quantity')->default(1);
            $table->decimal('total_value', 10, 2);
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['stream_id', 'created_at']);
        });

        // Co-hosts (for multi-host streams)
        Schema::create('stream_cohosts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stream_id')->constrained('live_streams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->enum('status', ['invited', 'accepted', 'declined', 'active', 'ended'])->default('invited');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['stream_id', 'user_id']);
        });

        // Stream followers notifications
        Schema::create('stream_subscriptions', function (Blueprint $table) {
            $table->foreignId('streamer_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->foreignId('subscriber_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->boolean('notify_live')->default(true);
            $table->timestamps();
            $table->primary(['streamer_id', 'subscriber_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stream_subscriptions');
        Schema::dropIfExists('stream_cohosts');
        Schema::dropIfExists('stream_gifts');
        Schema::dropIfExists('virtual_gifts');
        Schema::dropIfExists('stream_likes');
        Schema::dropIfExists('stream_comments');
        Schema::dropIfExists('stream_viewers');
        Schema::dropIfExists('live_streams');
    }
};
