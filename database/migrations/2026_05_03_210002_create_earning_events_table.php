<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('earning_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_uid')->unique();              // deterministic dedupe key (sha256 of source)
            $table->timestampTz('occurred_at')->index();
            $table->unsignedBigInteger('post_id')->nullable()->index();
            $table->unsignedBigInteger('comment_id')->nullable();
            $table->string('source_type');                       // 'post'|'comment'|'reply'|'live_stream'|'marketplace_order'|...
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('target_user_id')->index();
            $table->string('actor_role');                        // 'author'|'comment_author'|'reply_author'|'host'|'parent_thread'|'sharer'|'original_creator_royalty'|'fan_buyer'
            $table->string('stream');                            // 'engagement'|'fan_funding'|'marketplace'|'brand_deal'|'live_gifts'|'affiliate'
            $table->string('metric');                            // 'view'|'reaction'|'comment'|'watch_second'|...
            $table->unsignedInteger('raw_count')->default(1);
            $table->decimal('rate_tsh', 12, 4);
            $table->jsonb('multipliers');                        // {"watch_completion":2.0,"originality":1.0,...}
            $table->decimal('gross_credit', 12, 2);
            $table->decimal('platform_take', 12, 2)->default(0);
            $table->decimal('tra_wht_held', 12, 2)->default(0);
            $table->decimal('net_to_creator', 12, 2);
            $table->boolean('is_chargeable')->default(true);
            $table->string('charge_reason')->nullable();         // when is_chargeable=false, why
            $table->string('funding_source')->nullable();        // ad_impression_id|sponsor_id|fan_user_id|treasury
            $table->string('settlement_status')->default('pending'); // pending|cleared|reversed
            $table->timestampTz('cleared_at')->nullable();
            $table->timestampTz('reversed_at')->nullable();
            $table->string('reversal_reason')->nullable();
            $table->unsignedBigInteger('journal_line_pending_id')->nullable();
            $table->unsignedBigInteger('journal_line_cleared_id')->nullable();
            $table->unsignedBigInteger('journal_line_reversal_id')->nullable();
            $table->timestampsTz();

            $table->index(['target_user_id', 'settlement_status', 'occurred_at']);
            $table->index(['post_id', 'occurred_at']);
            $table->index(['actor_user_id', 'target_user_id', 'occurred_at']); // for AbuseGuard daily caps
            $table->index(['stream', 'metric', 'occurred_at']);
        });

        // Partial index for the daily sweep (settlement_status='pending').
        DB::statement(
            "CREATE INDEX earning_events_pending_idx ON earning_events (occurred_at) WHERE settlement_status = 'pending'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS earning_events_pending_idx');
        Schema::dropIfExists('earning_events');
    }
};
