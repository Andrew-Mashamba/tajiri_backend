<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("creator_fund_pools", function (Blueprint $table) {
            $table->id();
            $table->decimal("total_amount", 12, 2)->default(10000000);
            $table->string("currency")->default("TZS");
            $table->string("month")->unique();
            $table->integer("min_followers")->default(0);
            $table->integer("min_posts")->default(1);
            $table->integer("min_views")->default(0);
            $table->boolean("is_distributed")->default(false);
            $table->timestamp("distributed_at")->nullable();
            $table->timestamps();
        });

        Schema::create("creator_fund_payouts", function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger("pool_id")->index();
            $table->unsignedBigInteger("user_id")->index();
            $table->decimal("base_score", 12, 4)->default(0);
            $table->decimal("tier_multiplier", 4, 2)->default(1.0);
            $table->decimal("streak_multiplier", 4, 2)->default(1.0);
            $table->decimal("community_multiplier", 4, 2)->default(1.0);
            $table->decimal("virality_multiplier", 4, 2)->default(1.0);
            $table->decimal("effective_multiplier", 6, 2)->default(1.0);
            $table->decimal("final_score", 14, 4)->default(0);
            $table->decimal("payout_amount", 12, 2)->default(0);
            $table->string("payout_currency")->default("TZS");
            $table->string("status")->default("pending");
            $table->timestamp("paid_at")->nullable();
            $table->timestamps();
            $table->unique(["pool_id", "user_id"]);
            $table->foreign("pool_id")->references("id")->on("creator_fund_pools")->onDelete("cascade");
        });

        Schema::create("notification_templates", function (Blueprint $table) {
            $table->id();
            $table->string("key")->unique();
            $table->string("template_en");
            $table->string("template_sw");
            $table->json("slots")->nullable();
            $table->string("category")->default("general");
            $table->integer("max_per_day")->default(3);
            $table->integer("priority")->default(5);
            $table->boolean("is_active")->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("creator_fund_payouts");
        Schema::dropIfExists("creator_fund_pools");
        Schema::dropIfExists("notification_templates");
    }
};
