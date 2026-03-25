<?php

namespace App\Models\Shop;

use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Product extends Model
{
    use SoftDeletes;

    protected $attributes = [
        'views_count' => 0,
        'favorites_count' => 0,
        'orders_count' => 0,
        'reviews_count' => 0,
        'rating' => 0,
        'duration_minutes' => 0,
        'stock_quantity' => 0,
    ];

    protected $fillable = [
        'user_id',
        'title',
        'slug',
        'description',
        'type',
        'status',
        'price',
        'compare_at_price',
        'currency',
        'stock_quantity',
        'images',
        'thumbnail_url',
        'category_id',
        'tags',
        'condition',
        'location_name',
        'latitude',
        'longitude',
        'allow_pickup',
        'allow_delivery',
        'allow_shipping',
        'delivery_fee',
        'delivery_notes',
        'download_url',
        'duration_minutes',
        'views_count',
        'favorites_count',
        'orders_count',
        'rating',
        'reviews_count',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'compare_at_price' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'rating' => 'decimal:2',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'stock_quantity' => 'integer',
        'views_count' => 'integer',
        'favorites_count' => 'integer',
        'orders_count' => 'integer',
        'reviews_count' => 'integer',
        'duration_minutes' => 'integer',
        'images' => 'array',
        'tags' => 'array',
        'allow_pickup' => 'boolean',
        'allow_delivery' => 'boolean',
        'allow_shipping' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($product) {
            if (empty($product->slug)) {
                $product->slug = self::generateSlug($product->title);
            }
            if (empty($product->thumbnail_url) && !empty($product->images)) {
                $images = is_array($product->images) ? $product->images : json_decode($product->images, true);
                $product->thumbnail_url = $images[0] ?? null;
            }
        });
    }

    public static function generateSlug(string $title): string
    {
        $base = Str::slug($title);
        $slug = $base . '-' . Str::lower(Str::random(6));

        while (self::where('slug', $slug)->exists()) {
            $slug = $base . '-' . Str::lower(Str::random(6));
        }

        return $slug;
    }

    // Relationships

    public function seller(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(ProductFavorite::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', 'active')->where('stock_quantity', '>', 0);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByCondition($query, string $condition)
    {
        return $query->where('condition', $condition);
    }

    public function scopeInPriceRange($query, ?float $min, ?float $max)
    {
        if ($min !== null) {
            $query->where('price', '>=', $min);
        }
        if ($max !== null) {
            $query->where('price', '<=', $max);
        }
        return $query;
    }

    public function scopeNearby($query, float $lat, float $lng, float $radiusKm = 10)
    {
        return $query->selectRaw("*, (6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) AS distance", [$lat, $lng, $lat])
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->having('distance', '<', $radiusKm)
            ->orderBy('distance');
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('title', 'ILIKE', "%{$term}%")
              ->orWhere('description', 'ILIKE', "%{$term}%");
        });
    }

    // Helpers

    public function isOwnedBy(int $userId): bool
    {
        return $this->user_id === $userId;
    }

    public function isAvailable(): bool
    {
        return $this->status === 'active' && $this->stock_quantity > 0;
    }

    public function hasStock(int $quantity = 1): bool
    {
        return $this->stock_quantity >= $quantity;
    }

    public function recalculateRating(): void
    {
        $stats = $this->reviews()->whereNull('deleted_at')->selectRaw('AVG(rating) as avg_rating, COUNT(*) as count')->first();
        $this->update([
            'rating' => round($stats->avg_rating ?? 0, 2),
            'reviews_count' => $stats->count ?? 0,
        ]);
    }
}
