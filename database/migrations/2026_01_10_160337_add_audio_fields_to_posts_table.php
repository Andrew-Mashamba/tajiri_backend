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
        Schema::table('posts', function (Blueprint $table) {
            // Background color for text-only posts (hex color code)
            $table->string('background_color', 7)->nullable()->after('content');

            // Audio post fields
            $table->string('audio_path')->nullable()->after('background_color');
            $table->integer('audio_duration')->nullable()->after('audio_path');
            $table->json('audio_waveform')->nullable()->after('audio_duration');

            // Cover image for audio posts
            $table->string('cover_image_path')->nullable()->after('audio_waveform');

            // Music track reference (for short videos with music)
            $table->foreignId('music_track_id')->nullable()->after('cover_image_path')
                ->constrained('music_tracks')->nullOnDelete();
            $table->integer('music_start_time')->nullable()->after('music_track_id');
            $table->decimal('original_audio_volume', 3, 2)->default(1.0)->after('music_start_time');
            $table->decimal('music_volume', 3, 2)->default(1.0)->after('original_audio_volume');

            // Video speed multiplier
            $table->decimal('video_speed', 3, 2)->default(1.0)->after('music_volume');

            // Text overlays for videos (JSON array)
            $table->json('text_overlays')->nullable()->after('video_speed');

            // Video filter applied
            $table->string('video_filter', 50)->nullable()->after('text_overlays');

            // Note: post_type is a varchar column, so new types are automatically supported
            // New post_type values: 'short_video', 'audio', 'audio_text', 'image_text'
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropForeign(['music_track_id']);
            $table->dropColumn([
                'background_color',
                'audio_path',
                'audio_duration',
                'audio_waveform',
                'cover_image_path',
                'music_track_id',
                'music_start_time',
                'original_audio_volume',
                'music_volume',
                'video_speed',
                'text_overlays',
                'video_filter',
            ]);
        });

        // Note: PostgreSQL doesn't easily allow removing enum values
        // The enum type will retain the new values after rollback
    }
};
