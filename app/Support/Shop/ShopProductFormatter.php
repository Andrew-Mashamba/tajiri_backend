<?php

namespace App\Support\Shop;

use App\Models\Shop\Product;

/**
 * JSON shapes aligned with TAJIRI Flutter ShopService / Product.fromJson.
 */
class ShopProductFormatter
{
    /**
     * @param  array<int>  $favoriteProductIds
     */
    public static function product(Product $product, array $favoriteProductIds = []): array
    {
        $seller = $product->seller;
        $data = $product->toArray();
        $data['seller'] = $seller ? [
            'id' => $seller->id,
            'first_name' => $seller->first_name,
            'last_name' => $seller->last_name,
            'username' => $seller->username,
            'profile_photo_path' => $seller->profile_photo_path,
            'is_verified' => (bool) ($seller->is_verified ?? false),
            'rating' => 0.0,
            'total_sales' => 0,
            'product_count' => 0,
        ] : null;
        $data['is_favorited'] = in_array($product->id, $favoriteProductIds);
        unset($data['deleted_at']);

        return $data;
    }

    /**
     * Minimal product for cart lines (still valid for Flutter Product.fromJson).
     *
     * @param  array<string, mixed>|null  $sellerOverride
     */
    public static function cartLineProduct(Product $product, ?array $sellerOverride = null): array
    {
        $seller = $sellerOverride;
        if ($seller === null && $product->relationLoaded('seller') && $product->seller) {
            $s = $product->seller;
            $seller = [
                'id' => $s->id,
                'first_name' => $s->first_name,
                'last_name' => $s->last_name,
                'username' => $s->username,
                'profile_photo_path' => $s->profile_photo_path,
                'is_verified' => (bool) ($s->is_verified ?? false),
                'rating' => 0.0,
                'total_sales' => 0,
                'product_count' => 0,
            ];
        }

        return [
            'id' => $product->id,
            'user_id' => $product->user_id,
            'title' => $product->title,
            'description' => $product->description ?? '',
            'slug' => $product->slug,
            'type' => $product->type,
            'status' => $product->status,
            'price' => (float) $product->price,
            'compare_at_price' => $product->compare_at_price !== null ? (float) $product->compare_at_price : null,
            'currency' => $product->currency ?? 'TZS',
            'stock_quantity' => (int) $product->stock_quantity,
            'images' => $product->images ?? [],
            'thumbnail_url' => $product->thumbnail_url,
            'category_id' => $product->category_id,
            'tags' => $product->tags ?? [],
            'condition' => $product->condition,
            'location_name' => $product->location_name,
            'latitude' => $product->latitude,
            'longitude' => $product->longitude,
            'allow_pickup' => (bool) $product->allow_pickup,
            'allow_delivery' => (bool) $product->allow_delivery,
            'allow_shipping' => (bool) $product->allow_shipping,
            'delivery_fee' => $product->delivery_fee !== null ? (float) $product->delivery_fee : null,
            'delivery_notes' => $product->delivery_notes,
            'download_url' => $product->download_url,
            'duration_minutes' => $product->duration_minutes,
            'views_count' => (int) $product->views_count,
            'favorites_count' => (int) $product->favorites_count,
            'orders_count' => (int) $product->orders_count,
            'rating' => (float) $product->rating,
            'reviews_count' => (int) $product->reviews_count,
            'seller' => $seller,
            'category' => $product->relationLoaded('category') && $product->category
                ? $product->category->toArray()
                : null,
            'is_favorited' => false,
            'created_at' => $product->created_at?->toIso8601String(),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];
    }
}
