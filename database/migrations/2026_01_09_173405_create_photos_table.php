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
        Schema::create('photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user_profiles')->onDelete('cascade');
            $table->foreignId('album_id')->nullable()->constrained('photo_albums')->onDelete('set null');
            $table->string('file_path');
            $table->string('thumbnail_path')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->text('caption')->nullable();
            $table->string('location_name')->nullable();
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('album_id');
        });

        // Add foreign key to photo_albums for cover_photo
        Schema::table('photo_albums', function (Blueprint $table) {
            $table->foreign('cover_photo_id')->references('id')->on('photos')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('photos');
    }
};
