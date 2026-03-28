<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_events')) {
            Schema::create('user_events', function (Blueprint $table) {
                $table->id();
                $table->bigInteger('user_id');
                $table->string('event_type', 30);
                $table->bigInteger('post_id')->nullable();
                $table->bigInteger('creator_id')->nullable();
                $table->integer('duration_ms')->default(0);
                $table->uuid('session_id')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['user_id', 'created_at'], 'idx_ue_user');
                $table->index(['event_type', 'created_at'], 'idx_ue_type');
            });

            // Partial index — only index rows where post_id is not null
            DB::statement('CREATE INDEX idx_ue_post ON user_events(post_id) WHERE post_id IS NOT NULL');
        }
    }

    public function down(): void
    {
        // Only drop if we created it (table may pre-exist from another migration)
        Schema::dropIfExists('user_events');
    }
};
