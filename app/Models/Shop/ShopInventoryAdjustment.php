<?php

namespace App\Models\Shop;

use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopInventoryAdjustment extends Model
{
    protected $table = 'shop_inventory_adjustments';

    protected $fillable = [
        'product_id',
        'changed_by',
        'quantity_delta',
        'quantity_after',
        'reason',
    ];

    protected $casts = [
        'quantity_delta' => 'integer',
        'quantity_after' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'changed_by');
    }
}
