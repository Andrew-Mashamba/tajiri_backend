<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Music artists
        Schema::create('music_artists', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('image_path')->nullable();
            $table->text('bio')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->integer('followers_count')->default(0);
            $table->timestamps();
        });

        // Music tracks
        Schema::create('music_tracks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->foreignId('artist_id')->constrained('music_artists')->cascadeOnDelete();
            $table->string('album')->nullable();
            $table->string('audio_path');
            $table->string('cover_path')->nullable();
            $table->integer('duration'); // seconds
            $table->string('genre')->nullable();
            $table->integer('bpm')->nullable();
            $table->boolean('is_explicit')->default(false);
            $table->integer('uses_count')->default(0);
            $table->integer('plays_count')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_trending')->default(false);
            $table->timestamps();

            $table->index('artist_id');
            $table->index(['is_featured', 'is_trending']);
        });

        // Saved music
        Schema::create('saved_music', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->foreignId('track_id')->constrained('music_tracks')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'track_id']);
        });

        // Music categories
        Schema::create('music_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('icon')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();
        });

        // Track categories pivot
        Schema::create('music_track_categories', function (Blueprint $table) {
            $table->foreignId('track_id')->constrained('music_tracks')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('music_categories')->cascadeOnDelete();
            $table->primary(['track_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('music_track_categories');
        Schema::dropIfExists('music_categories');
        Schema::dropIfExists('saved_music');
        Schema::dropIfExists('music_tracks');
        Schema::dropIfExists('music_artists');
    }
};
