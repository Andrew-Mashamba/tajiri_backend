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
        Schema::create('user_events', function (Blueprint $table) {
            $table->id(); // bigint

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('event_type', 32);
            $table->unsignedBigInteger('post_id')->nullable();
            $table->unsignedBigInteger('creator_id')->nullable();
            $table->dateTime('timestamp');
            $table->integer('duration_ms')->nullable()->default(0);
            $table->uuid('session_id');
            $table->jsonb('metadata')->nullable();

            $table->index('user_id');
            $table->index('post_id');
            $table->index('creator_id');
            $table->index('timestamp');
            $table->index('session_id');

            // Composite index for deduplication reads (also backed by a UNIQUE constraint below).
            $table->index(['user_id', 'event_type', 'post_id', 'timestamp'], 'user_events_dedupe_idx');

            // Prevent exact duplicates at DB level (Postgres: NULL post_id will not dedupe).
            $table->unique(['user_id', 'event_type', 'post_id', 'timestamp'], 'user_events_dedupe_unique');

            $table->timestamps();
        });

        // Postgres CHECK constraint for allowed event types.
        DB::statement(
            "ALTER TABLE user_events
             ADD CONSTRAINT user_events_event_type_check
             CHECK (event_type IN ('view','like','share','save','scroll_past','dwell','comment','follow','unfollow'))"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_events');
    }
};
