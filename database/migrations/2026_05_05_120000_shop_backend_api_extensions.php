<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supports docs/shop/shop_backend_api.md extensions (seller shop, reports,
 * commerce analytics batch, inventory history, lightweight ads / affiliate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_shop_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->onDelete('cascade');
            $table->string('store_name')->nullable();
            $table->string('headline')->nullable();
            $table->text('description')->nullable();
            $table->string('banner_image_url')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('accent_hex', 16)->nullable();
            $table->json('social_links')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('shop_product_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->string('reason', 64);
            $table->text('detail')->nullable();
            $table->string('status', 32)->default('pending');
            $table->text('moderator_notes')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['product_id', 'created_at']);
        });

        Schema::create('shop_commerce_analytics_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('event_name', 128)->index();
            $table->json('properties')->nullable();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->timestamps();
            $table->index(['created_at']);
        });

        Schema::create('shop_inventory_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('changed_by')->constrained('users')->onDelete('cascade');
            $table->integer('quantity_delta');
            $table->integer('quantity_after');
            $table->string('reason', 128)->nullable();
            $table->timestamps();

            $table->index(['product_id', 'created_at']);
        });

        Schema::create('shop_ad_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('status', 32)->default('draft');
            $table->decimal('daily_budget_tzs', 15, 2)->nullable();
            $table->decimal('total_budget_tzs', 15, 2)->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->json('targeting')->nullable();
            $table->json('creative')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('shop_affiliate_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('code', 32)->unique();
            $table->string('label')->nullable();
            $table->decimal('commission_percent', 5, 2)->default(5.0);
            $table->timestamps();
        });

        Schema::create('shop_affiliate_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')->constrained('shop_affiliate_links')->onDelete('cascade');
            $table->foreignId('referrer_user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('order_id')->nullable()->constrained()->onDelete('set null');
            $table->decimal('amount_tzs', 15, 2)->default(0);
            $table->string('status', 32)->default('pending');
            $table->timestamps();

            $table->index(['referrer_user_id', 'status']);
        });

        Schema::create('shop_seller_bans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_user_id')->unique()->constrained('users')->onDelete('cascade');
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('shop_live_session_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_stream_id')->constrained('live_streams')->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['live_stream_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_live_session_products');
        Schema::dropIfExists('shop_seller_bans');
        Schema::dropIfExists('shop_affiliate_commissions');
        Schema::dropIfExists('shop_affiliate_links');
        Schema::dropIfExists('shop_ad_campaigns');
        Schema::dropIfExists('shop_inventory_adjustments');
        Schema::dropIfExists('shop_commerce_analytics_events');
        Schema::dropIfExists('shop_product_reports');
        Schema::dropIfExists('seller_shop_profiles');
    }
};
