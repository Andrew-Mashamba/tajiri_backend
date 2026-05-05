<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sponsored_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('sponsor_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('creator_user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('budget', 12, 2)->default(0);
            $table->string('currency', 10)->default('TSh');
            $table->string('status', 20)->default('draft');
            $table->string('tier_required', 20)->default('star');
            $table->integer('impressions_target')->default(0);
            $table->integer('impressions_delivered')->default(0);
            $table->timestamps();

            $table->index('sponsor_user_id');
            $table->index('creator_user_id');
            $table->index('status');
        });

        Schema::create('collaboration_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('suggested_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason')->default('');
            $table->decimal('compatibility_score', 5, 2)->default(0);
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->index('user_id');
            $table->index(['user_id', 'status']);
            $table->unique(['user_id', 'suggested_user_id']);
        });

        Schema::create('creator_battles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_a_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('creator_b_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('post_a_id')->nullable()->constrained('posts')->nullOnDelete();
            $table->foreignId('post_b_id')->nullable()->constrained('posts')->nullOnDelete();
            $table->string('topic')->default('');
            $table->string('status', 20)->default('active');
            $table->integer('votes_a')->default(0);
            $table->integer('votes_b')->default(0);
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('creator_battle_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('battle_id')->constrained('creator_battles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('side', 1);
            $table->timestamps();

            $table->unique(['battle_id', 'user_id']);
        });

        Schema::create('user_engagement_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('level', 20)->default('casual');
            $table->integer('weekly_actions')->default(0);
            $table->integer('streak_days')->default(0);
            $table->timestamps();

            $table->unique('user_id');
            $table->index('level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_battle_votes');
        Schema::dropIfExists('creator_battles');
        Schema::dropIfExists('collaboration_suggestions');
        Schema::dropIfExists('sponsored_posts');
        Schema::dropIfExists('user_engagement_levels');
    }
};
