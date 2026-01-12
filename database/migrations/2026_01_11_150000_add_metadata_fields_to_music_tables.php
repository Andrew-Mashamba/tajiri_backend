<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add user_id to music_artists (link artist to user profile)
        Schema::table('music_artists', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('user_profiles')->nullOnDelete();
            $table->integer('monthly_listeners')->default(0)->after('followers_count');
            $table->integer('tracks_count')->default(0)->after('monthly_listeners');
            $table->index('user_id');
        });

        // Add metadata fields to music_tracks
        Schema::table('music_tracks', function (Blueprint $table) {
            // Uploader info
            $table->foreignId('uploaded_by')->nullable()->after('artist_id')->constrained('user_profiles')->nullOnDelete();

            // Audio metadata extracted from file
            $table->integer('bitrate')->nullable()->after('bpm'); // kbps
            $table->integer('sample_rate')->nullable()->after('bitrate'); // Hz (e.g., 44100)
            $table->tinyInteger('channels')->nullable()->after('sample_rate'); // 1=mono, 2=stereo
            $table->bigInteger('file_size')->nullable()->after('channels'); // bytes
            $table->string('codec', 50)->nullable()->after('file_size'); // mp3, aac, flac, etc.
            $table->string('file_format', 20)->nullable()->after('codec'); // mp3, wav, m4a, etc.

            // ID3 tag metadata
            $table->string('composer')->nullable()->after('album');
            $table->string('publisher')->nullable()->after('composer');
            $table->year('release_year')->nullable()->after('publisher');
            $table->tinyInteger('track_number')->nullable()->after('release_year');
            $table->text('lyrics')->nullable()->after('track_number');
            $table->text('comment')->nullable()->after('lyrics');

            // Additional info
            $table->string('isrc', 12)->nullable()->after('comment'); // International Standard Recording Code
            $table->string('copyright')->nullable()->after('isrc');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('approved')->after('is_trending');
            $table->text('rejection_reason')->nullable()->after('status');

            // Indexes
            $table->index('uploaded_by');
            $table->index('status');
            $table->index('release_year');
        });
    }

    public function down(): void
    {
        Schema::table('music_tracks', function (Blueprint $table) {
            $table->dropForeign(['uploaded_by']);
            $table->dropIndex(['uploaded_by']);
            $table->dropIndex(['status']);
            $table->dropIndex(['release_year']);
            $table->dropColumn([
                'uploaded_by',
                'bitrate',
                'sample_rate',
                'channels',
                'file_size',
                'codec',
                'file_format',
                'composer',
                'publisher',
                'release_year',
                'track_number',
                'lyrics',
                'comment',
                'isrc',
                'copyright',
                'status',
                'rejection_reason',
            ]);
        });

        Schema::table('music_artists', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id']);
            $table->dropColumn(['user_id', 'monthly_listeners', 'tracks_count']);
        });
    }
};
