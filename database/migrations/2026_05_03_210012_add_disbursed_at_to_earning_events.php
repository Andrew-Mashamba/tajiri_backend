<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('earning_events', function (Blueprint $table) {
            $table->timestampTz('disbursed_at')->nullable()->after('reversed_at');
            $table->index(['target_user_id', 'settlement_status', 'disbursed_at'], 'earning_events_payout_idx');
        });
    }

    public function down(): void
    {
        Schema::table('earning_events', function (Blueprint $table) {
            $table->dropIndex('earning_events_payout_idx');
            $table->dropColumn('disbursed_at');
        });
    }
};
