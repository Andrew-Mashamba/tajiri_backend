<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // User wallets
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('user_profiles')->cascadeOnDelete();
            $table->decimal('balance', 15, 2)->default(0);
            $table->decimal('pending_balance', 15, 2)->default(0);
            $table->string('currency')->default('TZS');
            $table->boolean('is_active')->default(true);
            $table->string('pin_hash')->nullable(); // Hashed transaction PIN
            $table->integer('failed_pin_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();
        });

        // Linked mobile money accounts
        Schema::create('mobile_money_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->string('provider'); // mpesa, tigopesa, airtelmoney, halopesa
            $table->string('phone_number');
            $table->string('account_name');
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider', 'phone_number']);
        });

        // Wallet transactions
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id')->unique();
            $table->foreignId('wallet_id')->constrained('wallets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->string('type'); // deposit, withdrawal, transfer_in, transfer_out, payment, refund
            $table->decimal('amount', 15, 2);
            $table->decimal('fee', 10, 2)->default(0);
            $table->decimal('balance_before', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->string('status')->default('pending'); // pending, completed, failed, cancelled
            $table->string('payment_method')->nullable(); // mobile_money, bank, wallet
            $table->string('provider')->nullable(); // mpesa, tigopesa, etc.
            $table->string('external_transaction_id')->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'status']);
            $table->index(['user_id', 'type']);
        });

        // P2P Transfers
        Schema::create('wallet_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_id')->unique();
            $table->foreignId('sender_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->decimal('fee', 10, 2)->default(0);
            $table->string('status')->default('pending'); // pending, completed, failed, reversed
            $table->text('note')->nullable();
            $table->foreignId('sender_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->foreignId('recipient_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->timestamps();
        });

        // Payment requests
        Schema::create('payment_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_id')->unique();
            $table->foreignId('requester_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->foreignId('payer_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->text('description')->nullable();
            $table->string('status')->default('pending'); // pending, paid, declined, expired, cancelled
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->timestamps();
        });

        // Bills and payments to services
        Schema::create('bill_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_id')->unique();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->string('biller_code'); // Utility code
            $table->string('biller_name');
            $table->string('account_number');
            $table->decimal('amount', 15, 2);
            $table->decimal('fee', 10, 2)->default(0);
            $table->string('status')->default('pending');
            $table->string('reference_number')->nullable();
            $table->json('biller_response')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->timestamps();
        });

        // Transaction limits
        Schema::create('transaction_limits', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_type');
            $table->decimal('min_amount', 15, 2)->default(100);
            $table->decimal('max_amount', 15, 2)->default(5000000);
            $table->decimal('daily_limit', 15, 2)->default(10000000);
            $table->decimal('monthly_limit', 15, 2)->default(50000000);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Fee configurations
        Schema::create('transaction_fees', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_type');
            $table->string('provider')->nullable();
            $table->string('fee_type'); // flat, percentage
            $table->decimal('fee_value', 10, 4);
            $table->decimal('min_fee', 10, 2)->nullable();
            $table->decimal('max_fee', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_fees');
        Schema::dropIfExists('transaction_limits');
        Schema::dropIfExists('bill_payments');
        Schema::dropIfExists('payment_requests');
        Schema::dropIfExists('wallet_transfers');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('mobile_money_accounts');
        Schema::dropIfExists('wallets');
    }
};
