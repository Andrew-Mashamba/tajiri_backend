<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class ScoringConfig extends Model
{
    protected $table = 'scoring_config';
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['key', 'value', 'description', 'updated_at'];

    protected $casts = ['value' => 'float'];

    /**
     * Get a scoring weight, cached for 5 minutes.
     */
    public static function weight(string $key, float $default = 0): float
    {
        return Cache::remember("scoring_config:{$key}", 300, function () use ($key, $default) {
            $row = static::find($key);
            return $row ? $row->value : $default;
        });
    }

    /**
     * Get all weights as associative array, cached.
     */
    public static function allWeights(): array
    {
        return Cache::remember('scoring_config:all', 300, function () {
            return static::pluck('value', 'key')->toArray();
        });
    }
}
