<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clips (short videos)
        Schema::create('clips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->string('video_path');
            $table->string('thumbnail_path')->nullable();
            $table->text('caption')->nullable();
            $table->integer('duration'); // seconds (max 60)
            $table->foreignId('music_id')->nullable()->constrained('music_tracks')->nullOnDelete();
            $table->integer('music_start')->nullable();
            $table->json('hashtags')->nullable();
            $table->json('mentions')->nullable();
            $table->json('effects')->nullable();
            $table->string('location_name')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->enum('privacy', ['public', 'friends', 'private'])->default('public');
            $table->boolean('allow_comments')->default(true);
            $table->boolean('allow_duet')->default(true);
            $table->boolean('allow_stitch')->default(true);
            $table->boolean('allow_download')->default(true);
            $table->integer('views_count')->default(0);
            $table->integer('likes_count')->default(0);
            $table->integer('comments_count')->default(0);
            $table->integer('shares_count')->default(0);
            $table->integer('saves_count')->default(0);
            $table->integer('duets_count')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->string('status')->default('published'); // draft, published, removed
            $table->foreignId('original_clip_id')->nullable()->constrained('clips')->nullOnDelete(); // for duets/stitches
            $table->enum('clip_type', ['original', 'duet', 'stitch'])->default('original');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        // Clip likes
        Schema::create('clip_likes', function (Blueprint $table) {
            $table->foreignId('clip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['clip_id', 'user_id']);
        });

        // Clip comments
        Schema::create('clip_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('clip_comments')->cascadeOnDelete();
            $table->text('content');
            $table->integer('likes_count')->default(0);
            $table->integer('replies_count')->default(0);
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['clip_id', 'created_at']);
        });

        // Clip comment likes
        Schema::create('clip_comment_likes', function (Blueprint $table) {
            $table->foreignId('comment_id')->constrained('clip_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['comment_id', 'user_id']);
        });

        // Saved clips
        Schema::create('saved_clips', function (Blueprint $table) {
            $table->foreignId('clip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->foreignId('collection_id')->nullable();
            $table->timestamps();
            $table->primary(['clip_id', 'user_id']);
        });

        // Clip collections (for organizing saved clips)
        Schema::create('clip_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->string('name');
            $table->enum('privacy', ['public', 'private'])->default('private');
            $table->timestamps();
        });

        // Clip shares
        Schema::create('clip_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->string('platform')->nullable(); // internal, whatsapp, instagram, etc.
            $table->timestamps();
        });

        // Clip hashtags
        Schema::create('clip_hashtags', function (Blueprint $table) {
            $table->id();
            $table->string('tag')->unique();
            $table->integer('clips_count')->default(0);
            $table->integer('views_count')->default(0);
            $table->boolean('is_trending')->default(false);
            $table->timestamps();
        });

        // Clip hashtag pivot
        Schema::create('clip_hashtag_pivot', function (Blueprint $table) {
            $table->foreignId('clip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hashtag_id')->constrained('clip_hashtags')->cascadeOnDelete();
            $table->primary(['clip_id', 'hashtag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clip_hashtag_pivot');
        Schema::dropIfExists('clip_hashtags');
        Schema::dropIfExists('clip_shares');
        Schema::dropIfExists('clip_collections');
        Schema::dropIfExists('saved_clips');
        Schema::dropIfExists('clip_comment_likes');
        Schema::dropIfExists('clip_comments');
        Schema::dropIfExists('clip_likes');
        Schema::dropIfExists('clips');
    }
};
