<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('earnings_reserve_ledger', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->decimal('delta_tsh', 14, 2);              // + accrual / – drawdown
            $table->decimal('balance_after_tsh', 14, 2);      // running balance for fast latest-balance queries
            $table->string('reason');                          // 'accrual_5pct'|'phase2_floor_topup'|'reversal'|'mwanzo_subsidy'
            $table->unsignedBigInteger('journal_line_id')->nullable();
            $table->timestampsTz();

            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('earnings_reserve_ledger');
    }
};
