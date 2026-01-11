<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Resumable upload system for large video files.
     * Supports pause/resume functionality.
     */
    public function up(): void
    {
        Schema::create('chunk_uploads', function (Blueprint $table) {
            $table->id();
            $table->string('upload_id', 64)->unique();
            $table->foreignId('user_id')->constrained('user_profiles')->onDelete('cascade');

            // File metadata
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('total_size');
            $table->unsignedInteger('total_chunks');
            $table->unsignedBigInteger('chunk_size')->default(5242880); // 5MB default

            // Progress tracking
            $table->unsignedInteger('uploaded_chunks')->default(0);
            $table->unsignedBigInteger('uploaded_bytes')->default(0);
            $table->json('completed_chunks')->nullable(); // Array of completed chunk numbers

            // Storage paths
            $table->string('temp_directory');
            $table->string('final_path')->nullable();

            // Clip metadata (stored during init, used on completion)
            $table->text('caption')->nullable();
            $table->json('hashtags')->nullable();
            $table->json('mentions')->nullable();
            $table->string('location_name')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('privacy')->default('public');
            $table->boolean('allow_comments')->default(true);
            $table->boolean('allow_duet')->default(true);
            $table->boolean('allow_stitch')->default(true);
            $table->boolean('allow_download')->default(true);
            $table->foreignId('music_id')->nullable()->constrained('music_tracks');
            $table->unsignedInteger('music_start')->nullable();
            $table->foreignId('original_clip_id')->nullable()->constrained('clips');
            $table->string('clip_type')->default('original');

            // Status
            $table->enum('status', ['pending', 'uploading', 'processing', 'completed', 'failed', 'cancelled', 'expired'])->default('pending');
            $table->text('error_message')->nullable();

            // Timestamps
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable(); // Auto-cleanup incomplete uploads
            $table->timestamps();

            // Indexes
            $table->index(['user_id', 'status']);
            $table->index('expires_at');
            $table->index('upload_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chunk_uploads');
    }
};
