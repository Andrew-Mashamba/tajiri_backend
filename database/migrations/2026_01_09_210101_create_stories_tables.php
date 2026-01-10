<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stories
        Schema::create('stories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->enum('media_type', ['image', 'video', 'text'])->default('image');
            $table->string('media_path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->text('caption')->nullable();
            $table->integer('duration')->default(5);
            $table->json('text_overlays')->nullable();
            $table->json('stickers')->nullable();
            $table->string('filter')->nullable();
            $table->foreignId('music_id')->nullable()->constrained('music_tracks')->nullOnDelete();
            $table->integer('music_start')->nullable();
            $table->string('background_color')->nullable();
            $table->string('link_url')->nullable();
            $table->string('location_name')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->boolean('allow_replies')->default(true);
            $table->boolean('allow_sharing')->default(true);
            $table->enum('privacy', ['everyone', 'friends', 'close_friends'])->default('everyone');
            $table->integer('views_count')->default(0);
            $table->integer('reactions_count')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'expires_at']);
        });

        // Story views
        Schema::create('story_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->foreignId('viewer_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->timestamp('viewed_at');

            $table->unique(['story_id', 'viewer_id']);
        });

        // Story reactions
        Schema::create('story_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->string('emoji');
            $table->timestamps();

            $table->unique(['story_id', 'user_id']);
        });

        // Story replies
        Schema::create('story_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->text('content');
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });

        // Story highlights
        Schema::create('story_highlights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->string('title');
            $table->string('cover_path')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();
        });

        // Highlight stories pivot
        Schema::create('highlight_stories', function (Blueprint $table) {
            $table->foreignId('highlight_id')->constrained('story_highlights')->cascadeOnDelete();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->integer('order')->default(0);
            $table->primary(['highlight_id', 'story_id']);
        });

        // Close friends
        Schema::create('close_friends', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->foreignId('friend_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['user_id', 'friend_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('close_friends');
        Schema::dropIfExists('highlight_stories');
        Schema::dropIfExists('story_highlights');
        Schema::dropIfExists('story_replies');
        Schema::dropIfExists('story_reactions');
        Schema::dropIfExists('story_views');
        Schema::dropIfExists('stories');
    }
};
