<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaveCollection extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'is_private',
        'posts_count',
    ];

    protected $casts = [
        'is_private' => 'boolean',
        'posts_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    public function saves(): HasMany
    {
        return $this->hasMany(PostSave::class, 'collection_id');
    }

    public function posts()
    {
        return Post::whereIn('id', $this->saves()->pluck('post_id'));
    }
}
