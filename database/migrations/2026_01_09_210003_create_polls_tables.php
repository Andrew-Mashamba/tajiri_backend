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
        // Polls table
        Schema::create('polls', function (Blueprint $table) {
            $table->id();
            $table->string('question');
            $table->foreignId('creator_id')->constrained('user_profiles')->onDelete('cascade');
            $table->foreignId('post_id')->nullable()->constrained('posts')->onDelete('cascade');
            $table->foreignId('group_id')->nullable()->constrained('groups')->onDelete('cascade');
            $table->foreignId('page_id')->nullable()->constrained('pages')->onDelete('cascade');
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_multiple_choice')->default(false);
            $table->boolean('is_anonymous')->default(false);
            $table->boolean('show_results_before_voting')->default(true);
            $table->boolean('allow_add_options')->default(false);
            $table->unsignedInteger('total_votes')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('creator_id');
            $table->index('post_id');
            $table->index('group_id');
            $table->index('ends_at');
        });

        // Poll options table
        Schema::create('poll_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('polls')->onDelete('cascade');
            $table->string('option_text');
            $table->string('image_path')->nullable();
            $table->unsignedInteger('votes_count')->default(0);
            $table->unsignedSmallInteger('order')->default(0);
            $table->foreignId('added_by')->nullable()->constrained('user_profiles')->onDelete('set null');
            $table->timestamps();

            $table->index(['poll_id', 'order']);
        });

        // Poll votes table
        Schema::create('poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('polls')->onDelete('cascade');
            $table->foreignId('option_id')->constrained('poll_options')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('user_profiles')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['poll_id', 'option_id', 'user_id']);
            $table->index(['poll_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('poll_votes');
        Schema::dropIfExists('poll_options');
        Schema::dropIfExists('polls');
    }
};
