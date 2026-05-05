<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creators_fund_points', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('period_id');
            $table->unsignedBigInteger('user_id');
            $table->decimal('points', 20, 4)->default(0);
            $table->unsignedInteger('events_count')->default(0);
            $table->timestampTz('last_event_at')->nullable();
            $table->decimal('payout_tsh', 14, 2)->nullable();   // null until period settles
            $table->timestampsTz();

            $table->foreign('period_id')->references('id')->on('creators_fund_periods')->cascadeOnDelete();
            $table->unique(['period_id', 'user_id']);
            $table->index(['period_id', 'points']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creators_fund_points');
    }
};
