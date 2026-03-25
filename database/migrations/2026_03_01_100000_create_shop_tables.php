<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Product Categories
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_sw')->nullable();
            $table->string('slug')->unique();
            $table->string('icon')->nullable();
            $table->string('image_url')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('product_categories')->onDelete('set null');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('product_count')->default(0);
            $table->timestamps();

            $table->index(['parent_id', 'is_active', 'sort_order']);
        });

        // 2. Products
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description');
            $table->string('type')->default('physical'); // physical, digital, service
            $table->string('status')->default('draft'); // draft, active, sold_out, archived
            $table->decimal('price', 15, 2);
            $table->decimal('compare_at_price', 15, 2)->nullable();
            $table->string('currency', 3)->default('TZS');
            $table->integer('stock_quantity')->default(0);
            $table->json('images')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->onDelete('set null');
            $table->json('tags')->nullable();
            $table->string('condition')->nullable(); // new, used, refurbished

            // Location
            $table->string('location_name')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();

            // Delivery options
            $table->boolean('allow_pickup')->default(true);
            $table->boolean('allow_delivery')->default(false);
            $table->boolean('allow_shipping')->default(false);
            $table->decimal('delivery_fee', 15, 2)->nullable();
            $table->text('delivery_notes')->nullable();

            // Digital product
            $table->string('download_url')->nullable();

            // Service
            $table->integer('duration_minutes')->nullable();

            // Denormalized stats
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('favorites_count')->default(0);
            $table->unsignedInteger('orders_count')->default(0);
            $table->decimal('rating', 3, 2)->default(0);
            $table->unsignedInteger('reviews_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['category_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index(['latitude', 'longitude']);
        });

        // Add check constraints for PostgreSQL
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_type_check CHECK (type IN ('physical', 'digital', 'service'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('draft', 'active', 'sold_out', 'archived'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_condition_check CHECK (condition IS NULL OR condition IN ('new', 'used', 'refurbished'))");

        // 3. Product Favorites
        Schema::create('product_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['user_id', 'product_id']);
            $table->index(['user_id', 'created_at']);
        });

        // 4. Shopping Carts
        Schema::create('shopping_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique('user_id');
        });

        // 5. Cart Items
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('shopping_carts')->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->integer('quantity')->default(1);
            $table->timestamps();

            $table->unique(['cart_id', 'product_id']);
        });

        // 6. Orders
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('buyer_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('seller_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->integer('quantity');
            $table->decimal('unit_price', 15, 2);
            $table->decimal('subtotal', 15, 2);
            $table->decimal('delivery_fee', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);
            $table->string('currency', 3)->default('TZS');

            $table->string('status')->default('pending');
            $table->string('delivery_method')->default('pickup');
            $table->text('delivery_address')->nullable();
            $table->text('delivery_notes')->nullable();
            $table->string('tracking_number')->nullable();
            $table->timestamp('estimated_delivery')->nullable();

            // Payment
            $table->unsignedBigInteger('wallet_transaction_id')->nullable();
            $table->string('payment_status')->default('pending');
            $table->timestamp('paid_at')->nullable();

            // Cancellation
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['buyer_id', 'status', 'created_at']);
            $table->index(['seller_id', 'status', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending', 'confirmed', 'processing', 'shipped', 'delivered', 'completed', 'cancelled', 'refunded'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_delivery_method_check CHECK (delivery_method IN ('pickup', 'delivery', 'shipping', 'digital'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check CHECK (payment_status IN ('pending', 'paid', 'refunded'))");

        // 7. Order Status History
        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->string('status');
            $table->text('notes')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
        });

        // 8. Product Reviews
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('order_id')->nullable()->constrained()->onDelete('set null');
            $table->smallInteger('rating');
            $table->text('comment')->nullable();
            $table->json('images')->nullable();
            $table->boolean('is_verified_purchase')->default(false);
            $table->unsignedInteger('helpful_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['product_id', 'user_id']);
            $table->index(['product_id', 'rating', 'created_at']);
        });

        // 9. Review Helpful Votes
        Schema::create('review_helpful_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('product_reviews')->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['review_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_helpful_votes');
        Schema::dropIfExists('product_reviews');
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('shopping_carts');
        Schema::dropIfExists('product_favorites');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
    }
};
