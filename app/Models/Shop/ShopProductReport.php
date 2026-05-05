<?php

namespace App\Models\Shop;

use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopProductReport extends Model
{
    protected $table = 'shop_product_reports';

    protected $fillable = [
        'user_id',
        'product_id',
        'reason',
        'detail',
        'status',
        'moderator_notes',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'resolved_by');
    }
}
