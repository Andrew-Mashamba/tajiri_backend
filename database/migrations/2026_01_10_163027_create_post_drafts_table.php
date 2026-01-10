<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('post_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // Post type: text, photo, video, short_video, audio
            $table->string('post_type')->default('text');

            // Content
            $table->text('content')->nullable();
            $table->string('background_color', 7)->nullable();

            // Media (stored as JSON array of temporary file paths)
            $table->json('media_files')->nullable();
            $table->json('media_metadata')->nullable(); // thumbnails, dimensions, etc.

            // Audio specific
            $table->string('audio_path')->nullable();
            $table->integer('audio_duration')->nullable();
            $table->json('audio_waveform')->nullable();
            $table->string('cover_image_path')->nullable();

            // Video specific
            $table->foreignId('music_track_id')->nullable()->constrained('music_tracks')->nullOnDelete();
            $table->integer('music_start_time')->nullable();
            $table->decimal('original_audio_volume', 3, 2)->default(1.0);
            $table->decimal('music_volume', 3, 2)->default(0.5);
            $table->decimal('video_speed', 3, 2)->default(1.0);
            $table->json('text_overlays')->nullable();
            $table->string('video_filter')->nullable();

            // Settings
            $table->string('privacy')->default('public');
            $table->string('location_name')->nullable();
            $table->decimal('location_lat', 10, 8)->nullable();
            $table->decimal('location_lng', 11, 8)->nullable();
            $table->json('tagged_users')->nullable();

            // Scheduling
            $table->timestamp('scheduled_at')->nullable();

            // Draft metadata
            $table->string('title')->nullable(); // Optional title for draft identification
            $table->timestamp('last_edited_at')->nullable();
            $table->integer('auto_save_version')->default(1);

            $table->timestamps();

            // Indexes
            $table->index(['user_id', 'updated_at']);
            $table->index(['user_id', 'post_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('post_drafts');
    }
};
