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
        // Pages table
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('category');
            $table->string('subcategory')->nullable();
            $table->text('description')->nullable();
            $table->string('profile_photo_path')->nullable();
            $table->string('cover_photo_path')->nullable();
            $table->string('website')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->json('hours')->nullable(); // Operating hours
            $table->json('social_links')->nullable();
            $table->foreignId('creator_id')->constrained('user_profiles')->onDelete('cascade');
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('followers_count')->default(0);
            $table->unsignedInteger('posts_count')->default(0);
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index('category');
            $table->index('creator_id');
            $table->fullText(['name', 'description']);
        });

        // Page admins/roles table
        Schema::create('page_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('pages')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('user_profiles')->onDelete('cascade');
            $table->enum('role', ['admin', 'editor', 'moderator', 'analyst'])->default('admin');
            $table->timestamps();

            $table->unique(['page_id', 'user_id']);
        });

        // Page followers table
        Schema::create('page_followers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('pages')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('user_profiles')->onDelete('cascade');
            $table->boolean('notifications_enabled')->default(true);
            $table->timestamps();

            $table->unique(['page_id', 'user_id']);
            $table->index('user_id');
        });

        // Page likes table
        Schema::create('page_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('pages')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('user_profiles')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['page_id', 'user_id']);
        });

        // Page posts table (linking posts to pages)
        Schema::create('page_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('pages')->onDelete('cascade');
            $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
            $table->foreignId('posted_by')->constrained('user_profiles')->onDelete('cascade');
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();

            $table->unique(['page_id', 'post_id']);
        });

        // Page reviews table
        Schema::create('page_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('pages')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('user_profiles')->onDelete('cascade');
            $table->tinyInteger('rating'); // 1-5 stars
            $table->text('content')->nullable();
            $table->timestamps();

            $table->unique(['page_id', 'user_id']);
            $table->index(['page_id', 'rating']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('page_reviews');
        Schema::dropIfExists('page_posts');
        Schema::dropIfExists('page_likes');
        Schema::dropIfExists('page_followers');
        Schema::dropIfExists('page_roles');
        Schema::dropIfExists('pages');
    }
};
