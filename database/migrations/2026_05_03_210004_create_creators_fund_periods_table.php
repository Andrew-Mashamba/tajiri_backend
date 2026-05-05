<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creators_fund_periods', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('period_start');
            $table->timestampTz('period_end');
            $table->string('status')->default('open');      // 'open'|'distributing'|'settled'|'reversed'
            $table->string('phase');                        // 'phase_1'|'phase_2'

            // Phase 1 inputs
            $table->decimal('phase_1_committed_budget_tsh', 14, 2)->nullable();

            // Phase 2 inputs
            $table->decimal('ad_revenue_tsh', 14, 2)->nullable();
            $table->decimal('fan_funding_take_tsh', 14, 2)->nullable();
            $table->decimal('marketplace_take_tsh', 14, 2)->nullable();
            $table->decimal('brand_deal_take_tsh', 14, 2)->nullable();
            $table->decimal('live_gifts_take_tsh', 14, 2)->nullable();
            $table->decimal('ad_share_pct', 5, 4)->nullable();           // 0.70 in Phase 2
            $table->decimal('pass_through_share_pct', 5, 4)->nullable(); // 0.10 in Phase 2
            $table->decimal('treasury_topup_tsh', 14, 2)->nullable();

            // Computed
            $table->decimal('floor_tsh', 14, 2);
            $table->decimal('fund_size_tsh', 14, 2);
            $table->decimal('reserve_topup_tsh', 14, 2)->default(0);

            // Distribution
            $table->decimal('total_points', 20, 4)->nullable();
            $table->decimal('fund_per_point', 20, 8)->nullable();
            $table->unsignedInteger('eligible_creator_count')->nullable();
            $table->timestampTz('settled_at')->nullable();
            $table->unsignedBigInteger('settlement_journal_batch_id')->nullable();
            $table->timestampsTz();

            $table->unique(['period_start', 'period_end']);
            $table->index(['status', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creators_fund_periods');
    }
};
