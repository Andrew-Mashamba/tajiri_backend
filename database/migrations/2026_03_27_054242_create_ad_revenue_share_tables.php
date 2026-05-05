<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ad campaigns by advertisers
        Schema::create('ad_campaigns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('advertiser_user_id');
            $table->string('name');
            $table->string('status')->default('draft'); // draft, active, paused, completed
            $table->decimal('budget', 15, 2)->default(0);
            $table->decimal('spent', 15, 2)->default(0);
            $table->decimal('cpm_rate', 10, 2)->default(0); // cost per 1000 impressions
            $table->string('target_regions')->nullable(); // JSON array of region_ids
            $table->string('target_interests')->nullable(); // JSON array of interest tags
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index('advertiser_user_id');
            $table->index('status');
        });

        // Revenue earned by creators from ads shown on their content
        Schema::create('ad_revenue_shares', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('creator_id');
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('post_id')->nullable();
            $table->integer('impressions')->default(0);
            $table->decimal('revenue_earned', 15, 2)->default(0);
            $table->decimal('creator_share', 15, 2)->default(0); // 70% to creator
            $table->decimal('platform_share', 15, 2)->default(0); // 30% to platform
            $table->string('period')->nullable(); // e.g. '2026-03'
            $table->string('status')->default('pending'); // pending, paid
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index('creator_id');
            $table->index(['campaign_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_revenue_shares');
        Schema::dropIfExists('ad_campaigns');
    }
};
