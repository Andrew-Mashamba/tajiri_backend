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
        Schema::create('viewer_streaks', function (Blueprint $table) {
            $table->id(); // bigint

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->unique();
            $table->integer('current_streak_days')->default(0);
            $table->integer('longest_streak_days')->default(0);
            $table->date('last_active_date')->nullable();
            $table->boolean('is_frozen')->default(false);
            $table->dateTime('frozen_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('viewer_streaks');
    }
};
