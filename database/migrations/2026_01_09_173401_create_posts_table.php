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
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user_profiles')->onDelete('cascade');
            $table->text('content')->nullable();
            $table->enum('post_type', ['text', 'photo', 'video', 'poll', 'shared'])->default('text');
            $table->enum('privacy', ['public', 'friends', 'private'])->default('public');
            $table->string('location_name')->nullable();
            $table->json('tagged_users')->nullable();
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->unsignedInteger('shares_count')->default(0);
            $table->boolean('is_pinned')->default(false);
            $table->foreignId('original_post_id')->nullable()->constrained('posts')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'created_at']);
            $table->index('privacy');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
