<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->string('title');
            $table->text('story');
            $table->string('short_description', 500)->nullable();
            $table->decimal('goal_amount', 15, 2);
            $table->decimal('raised_amount', 15, 2)->default(0);
            $table->string('currency')->default('TZS');
            $table->string('status')->default('draft');
            $table->string('category')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamp('deadline')->nullable();
            $table->string('cover_image_path')->nullable();
            $table->json('media_paths')->nullable();
            $table->integer('donors_count')->default(0);
            $table->integer('shares_count')->default(0);
            $table->integer('views_count')->default(0);
            $table->boolean('allow_anonymous_donations')->default(true);
            $table->decimal('minimum_donation', 15, 2)->default(1000);
            $table->boolean('is_urgent')->default(false);
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('mobile_money_number')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('category');
        });

        Schema::create('campaign_donations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('donor_id')->nullable()->constrained('user_profiles')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('currency')->default('TZS');
            $table->boolean('is_anonymous')->default(false);
            $table->text('message')->nullable();
            $table->string('donor_name')->nullable();
            $table->string('donor_avatar_url')->nullable();
            $table->string('payment_ref')->nullable();
            $table->string('status')->default('pending');
            $table->string('payment_method')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'status']);
            $table->index('donor_id');
        });

        Schema::create('campaign_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('currency')->default('TZS');
            $table->string('status')->default('pending');
            $table->string('destination_type');
            $table->json('destination_details');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_withdrawals');
        Schema::dropIfExists('campaign_donations');
        Schema::dropIfExists('campaigns');
    }
};
