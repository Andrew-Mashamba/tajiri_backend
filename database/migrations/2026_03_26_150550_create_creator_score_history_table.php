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
        Schema::create('creator_score_history', function (Blueprint $table) {
            $table->id(); // bigint

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('score', 5, 2);
            $table->string('tier', 32);
            $table->date('snapshot_date');
            $table->jsonb('component_scores');
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index('snapshot_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('creator_score_history');
    }
};
