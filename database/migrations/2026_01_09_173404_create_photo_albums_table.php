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
        Schema::create('photo_albums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user_profiles')->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('privacy', ['public', 'friends', 'private'])->default('public');
            $table->unsignedBigInteger('cover_photo_id')->nullable();
            $table->unsignedInteger('photos_count')->default(0);
            $table->boolean('is_system_album')->default(false);
            $table->string('system_album_type')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('photo_albums');
    }
};
