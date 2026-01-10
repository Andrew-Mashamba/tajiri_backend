<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Creator subscription tiers
        Schema::create('subscription_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->string('billing_period')->default('monthly'); // monthly, yearly
            $table->json('benefits')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('subscriber_count')->default(0);
            $table->integer('order')->default(0);
            $table->timestamps();
        });

        // User subscriptions
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->foreignId('creator_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->foreignId('tier_id')->constrained('subscription_tiers')->cascadeOnDelete();
            $table->string('status')->default('active'); // active, cancelled, expired, paused
            $table->decimal('amount_paid', 10, 2);
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->string('payment_method')->nullable();
            $table->string('transaction_id')->nullable();
            $table->timestamps();

            $table->unique(['subscriber_id', 'creator_id']);
        });

        // Creator tips/donations (one-time)
        Schema::create('creator_tips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->foreignId('creator_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('message')->nullable();
            $table->string('payment_method');
            $table->string('transaction_id')->nullable();
            $table->string('status')->default('completed');
            $table->timestamps();
        });

        // Creator earnings tracking
        Schema::create('creator_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->string('type'); // subscription, tip, gift
            $table->decimal('gross_amount', 10, 2);
            $table->decimal('platform_fee', 10, 2);
            $table->decimal('net_amount', 10, 2);
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('tip_id')->nullable()->constrained('creator_tips')->nullOnDelete();
            $table->foreignId('gift_id')->nullable()->constrained('stream_gifts')->nullOnDelete();
            $table->string('status')->default('pending'); // pending, paid, failed
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        // Payouts
        Schema::create('creator_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('payment_method'); // mobile_money, bank_transfer
            $table->string('account_number');
            $table->string('account_name');
            $table->string('provider')->nullable(); // M-Pesa, Tigo Pesa, Airtel Money, etc.
            $table->string('status')->default('pending'); // pending, processing, completed, failed
            $table->string('transaction_id')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_payouts');
        Schema::dropIfExists('creator_earnings');
        Schema::dropIfExists('creator_tips');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_tiers');
    }
};
