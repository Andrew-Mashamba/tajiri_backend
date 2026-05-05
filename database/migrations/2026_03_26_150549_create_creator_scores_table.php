<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('creator_scores', function (Blueprint $table) {
            $table->id(); // bigint

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->unique();
            $table->decimal('score', 5, 2)->default(0.00);
            $table->string('tier', 32)->default('rising');
            $table->decimal('community_score', 5, 2)->default(0.00);
            $table->decimal('quality_score', 5, 2)->default(0.00);
            $table->decimal('consistency_score', 5, 2)->default(0.00);
            $table->decimal('tier_multiplier', 3, 2)->default(1.00);
            $table->dateTime('computed_at');

            $table->timestamps();
        });

        DB::statement(
            "ALTER TABLE creator_scores
             ADD CONSTRAINT creator_scores_score_range_check
             CHECK (score >= 0 AND score <= 100)"
        );

        DB::statement(
            "ALTER TABLE creator_scores
             ADD CONSTRAINT creator_scores_tier_check
             CHECK (tier IN ('rising','established','star','legend'))"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('creator_scores');
    }
};
