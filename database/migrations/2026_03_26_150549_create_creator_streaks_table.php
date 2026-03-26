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
        Schema::create('creator_streaks', function (Blueprint $table) {
            $table->id(); // bigint

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->unique();
            $table->integer('current_streak_days')->default(0);
            $table->integer('longest_streak_days')->default(0);
            $table->dateTime('last_post_at')->nullable();
            $table->integer('banked_skip_days')->default(0);
            $table->boolean('is_frozen')->default(false);
            $table->dateTime('frozen_at')->nullable();
            $table->decimal('streak_multiplier', 3, 2)->default(1.00);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('creator_streaks');
    }
};
