<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_tiers', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('tier')->default('mwanzo');                    // 'mwanzo'|'standard'|'verified'|'partner'
            $table->timestampTz('promoted_at')->useCurrent();
            $table->timestampTz('mwanzo_expires_at')->nullable();         // set to +30 days on first earning event
            $table->timestampTz('next_review_at')->nullable();
            $table->unsignedInteger('strike_count')->default(0);
            $table->boolean('monetization_paused')->default(false);       // strategy §8 inactivity rule
            $table->timestampTz('last_active_at')->nullable();
            $table->boolean('is_id_verified')->default(false);
            $table->string('payout_preference')->default('auto_daily');   // 'auto_daily'|'weekly_batch'
            $table->timestampsTz();

            $table->index(['tier', 'next_review_at']);
            $table->index(['monetization_paused', 'last_active_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_tiers');
    }
};
