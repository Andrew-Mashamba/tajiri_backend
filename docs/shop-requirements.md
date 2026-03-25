# TAJIRI Shop Backend Requirements (Laravel)

## Overview

This document outlines the Laravel backend requirements for the TAJIRI C2C (Consumer-to-Consumer) marketplace. Users can sell products/services from their profiles and buy from others. Payments are processed via TAJIRI Wallet.

---

## Database Schema

### 1. Products Table

```php
Schema::create('products', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade'); // seller
    $table->string('title');
    $table->string('slug')->unique();
    $table->text('description');
    $table->enum('type', ['physical', 'digital', 'service'])->default('physical');
    $table->enum('status', ['draft', 'active', 'sold_out', 'archived'])->default('draft');
    $table->decimal('price', 15, 2);
    $table->decimal('compare_at_price', 15, 2)->nullable(); // original price for discounts
    $table->string('currency', 3)->default('TZS');
    $table->integer('stock_quantity')->default(0);
    $table->json('images')->nullable(); // array of image URLs
    $table->string('thumbnail_url')->nullable();
    $table->foreignId('category_id')->nullable()->constrained('product_categories');
    $table->json('tags')->nullable();
    $table->enum('condition', ['new', 'used', 'refurbished'])->nullable();

    // Location (for pickup)
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

    // Stats (denormalized for performance)
    $table->unsignedInteger('views_count')->default(0);
    $table->unsignedInteger('favorites_count')->default(0);
    $table->unsignedInteger('orders_count')->default(0);
    $table->decimal('rating', 3, 2)->default(0);
    $table->unsignedInteger('reviews_count')->default(0);

    $table->timestamps();
    $table->softDeletes();

    // Indexes
    $table->index(['user_id', 'status']);
    $table->index(['category_id', 'status']);
    $table->index(['status', 'created_at']);
    $table->index(['latitude', 'longitude']);
});
```

### 2. Product Categories Table

```php
Schema::create('product_categories', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('name_sw')->nullable(); // Swahili name
    $table->string('slug')->unique();
    $table->string('icon')->nullable(); // icon name or URL
    $table->string('image_url')->nullable();
    $table->foreignId('parent_id')->nullable()->constrained('product_categories')->onDelete('set null');
    $table->integer('sort_order')->default(0);
    $table->boolean('is_active')->default(true);
    $table->unsignedInteger('product_count')->default(0); // denormalized
    $table->timestamps();

    $table->index(['parent_id', 'is_active', 'sort_order']);
});
```

### 3. Product Favorites Table

```php
Schema::create('product_favorites', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->foreignId('product_id')->constrained()->onDelete('cascade');
    $table->timestamps();

    $table->unique(['user_id', 'product_id']);
    $table->index(['user_id', 'created_at']);
});
```

### 4. Shopping Carts Table

```php
Schema::create('shopping_carts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->timestamps();

    $table->unique('user_id');
});

Schema::create('cart_items', function (Blueprint $table) {
    $table->id();
    $table->foreignId('cart_id')->constrained('shopping_carts')->onDelete('cascade');
    $table->foreignId('product_id')->constrained()->onDelete('cascade');
    $table->integer('quantity')->default(1);
    $table->timestamps();

    $table->unique(['cart_id', 'product_id']);
});
```

### 5. Orders Table

```php
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

    $table->enum('status', [
        'pending',
        'confirmed',
        'processing',
        'shipped',
        'delivered',
        'completed',
        'cancelled',
        'refunded'
    ])->default('pending');

    $table->enum('delivery_method', ['pickup', 'delivery', 'shipping', 'digital'])->default('pickup');
    $table->text('delivery_address')->nullable();
    $table->text('delivery_notes')->nullable();
    $table->string('tracking_number')->nullable();
    $table->timestamp('estimated_delivery')->nullable();

    // Payment
    $table->unsignedBigInteger('wallet_transaction_id')->nullable();
    $table->enum('payment_status', ['pending', 'paid', 'refunded'])->default('pending');
    $table->timestamp('paid_at')->nullable();

    // Cancellation
    $table->text('cancellation_reason')->nullable();
    $table->foreignId('cancelled_by')->nullable()->constrained('users');
    $table->timestamp('cancelled_at')->nullable();

    $table->timestamps();
    $table->softDeletes();

    // Indexes
    $table->index(['buyer_id', 'status', 'created_at']);
    $table->index(['seller_id', 'status', 'created_at']);
    $table->index(['status', 'created_at']);
});
```

### 6. Order Status History Table

```php
Schema::create('order_status_history', function (Blueprint $table) {
    $table->id();
    $table->foreignId('order_id')->constrained()->onDelete('cascade');
    $table->string('status');
    $table->text('notes')->nullable();
    $table->foreignId('changed_by')->nullable()->constrained('users');
    $table->timestamps();

    $table->index(['order_id', 'created_at']);
});
```

### 7. Product Reviews Table

```php
Schema::create('product_reviews', function (Blueprint $table) {
    $table->id();
    $table->foreignId('product_id')->constrained()->onDelete('cascade');
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->foreignId('order_id')->nullable()->constrained()->onDelete('set null');
    $table->tinyInteger('rating'); // 1-5
    $table->text('comment')->nullable();
    $table->json('images')->nullable();
    $table->boolean('is_verified_purchase')->default(false);
    $table->unsignedInteger('helpful_count')->default(0);
    $table->timestamps();
    $table->softDeletes();

    $table->unique(['product_id', 'user_id']);
    $table->index(['product_id', 'rating', 'created_at']);
});
```

### 8. Review Helpful Votes Table

```php
Schema::create('review_helpful_votes', function (Blueprint $table) {
    $table->id();
    $table->foreignId('review_id')->constrained('product_reviews')->onDelete('cascade');
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->timestamps();

    $table->unique(['review_id', 'user_id']);
});
```

---

## API Endpoints

### Base URL: `/api/v1/shop`

### Products

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| GET | `/products` | List products with filters | Optional |
| GET | `/products/featured` | Get featured products | Optional |
| GET | `/products/trending` | Get trending products | Optional |
| GET | `/products/recommended` | Get personalized recommendations | Required |
| GET | `/products/nearby` | Get products near location | Optional |
| GET | `/products/{id}` | Get single product | Optional |
| POST | `/products` | Create new product | Required |
| PUT | `/products/{id}` | Update product | Required (owner) |
| DELETE | `/products/{id}` | Delete product | Required (owner) |
| POST | `/products/{id}/view` | Increment view count | Optional |
| GET | `/sellers/{userId}/products` | Get seller's products | Optional |

### Categories

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| GET | `/categories` | List all categories | No |
| GET | `/categories/{id}` | Get category with children | No |
| GET | `/categories/{id}/products` | Get products in category | Optional |

### Favorites

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| GET | `/favorites` | Get user's favorites | Required |
| POST | `/products/{id}/favorite` | Toggle favorite | Required |
| DELETE | `/products/{id}/favorite` | Remove from favorites | Required |

### Cart

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| GET | `/cart` | Get user's cart | Required |
| POST | `/cart/items` | Add item to cart | Required |
| PUT | `/cart/items/{productId}` | Update item quantity | Required |
| DELETE | `/cart/items/{productId}` | Remove item from cart | Required |
| DELETE | `/cart` | Clear entire cart | Required |

### Orders

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| POST | `/orders` | Create new order | Required |
| GET | `/orders/buyer` | Get buyer's orders | Required |
| GET | `/orders/seller` | Get seller's orders | Required |
| GET | `/orders/{id}` | Get order details | Required (buyer/seller) |
| PUT | `/orders/{id}/status` | Update order status | Required (seller) |
| POST | `/orders/{id}/cancel` | Cancel order | Required (buyer/seller) |

### Reviews

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| GET | `/products/{id}/reviews` | Get product reviews | Optional |
| POST | `/products/{id}/reviews` | Create review | Required |
| PUT | `/reviews/{id}` | Update review | Required (owner) |
| DELETE | `/reviews/{id}` | Delete review | Required (owner) |
| POST | `/reviews/{id}/helpful` | Mark review as helpful | Required |

### Seller Dashboard

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| GET | `/seller/stats` | Get seller statistics | Required |
| GET | `/seller/products` | Get own products | Required |
| GET | `/seller/orders` | Get received orders | Required |

---

## Business Logic Rules

### Product Rules

1. **Stock Management:**
   - Decrement stock when order is confirmed (not when placed)
   - Auto-set status to `sold_out` when stock reaches 0
   - Prevent purchase if stock < requested quantity

2. **Pricing:**
   - `compare_at_price` must be > `price` if set
   - All prices stored in smallest unit (cents/senti) or as decimal

3. **Images:**
   - Maximum 10 images per product
   - First image is auto-set as thumbnail
   - Support base64 upload or URL reference

4. **Slug Generation:**
   - Auto-generate from title + random suffix
   - Must be unique

### Cart Rules

1. **Cart Lifecycle:**
   - Auto-create cart on first add
   - Cart persists until checkout or manual clear
   - Items from unavailable products are flagged

2. **Quantity Validation:**
   - Cannot exceed available stock
   - Minimum quantity is 1

3. **Price Calculation:**
   - Always use current product price (not cached)
   - Recalculate totals on every cart fetch

### Order Rules

1. **Order Creation:**
   - Validate stock availability
   - Deduct from TAJIRI Wallet immediately
   - Generate unique order number: `ORD-{YEAR}-{6-digit-sequence}`

2. **Status Transitions:**
   ```
   pending -> confirmed -> processing -> shipped -> delivered -> completed
                |              |           |          |
            cancelled      cancelled   cancelled   refunded
   ```

3. **Cancellation:**
   - Buyer can cancel if status is `pending`
   - Seller can cancel if status is `pending` or `confirmed`
   - Auto-refund to buyer's wallet on cancellation

4. **Completion:**
   - Buyer must confirm delivery within 7 days
   - Auto-complete after 7 days if no dispute

### Review Rules

1. **Eligibility:**
   - User must have completed order for the product
   - One review per user per product
   - Can update within 30 days

2. **Rating Calculation:**
   - Product rating = average of all review ratings
   - Update on review create/update/delete

### Payment Integration (TAJIRI Wallet)

1. **Purchase Flow:**
   ```
   1. User initiates checkout
   2. Verify wallet balance >= order total
   3. Create pending order
   4. Deduct from buyer's wallet
   5. Create wallet transaction (type: 'shop_purchase')
   6. Link transaction to order
   7. Update order payment_status to 'paid'
   8. Notify seller
   ```

2. **Refund Flow:**
   ```
   1. Order cancelled/refunded
   2. Credit buyer's wallet
   3. Create wallet transaction (type: 'shop_refund')
   4. Update order payment_status to 'refunded'
   5. Notify buyer
   ```
