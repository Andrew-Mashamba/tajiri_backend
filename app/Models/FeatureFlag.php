<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class FeatureFlag extends Model
{
    protected $table = 'feature_flags';
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['key', 'enabled', 'rollout_pct', 'description', 'updated_at'];

    protected $casts = ['enabled' => 'boolean', 'rollout_pct' => 'integer'];

    /**
     * Check if a feature is enabled for a given user.
     * Uses deterministic assignment: user_id % 100 < rollout_pct.
     */
    public static function isEnabled(string $key, ?int $userId = null): bool
    {
        $flag = Cache::remember("feature_flag:{$key}", 60, function () use ($key) {
            return static::find($key);
        });

        if (!$flag || !$flag->enabled) {
            return false;
        }

        if ($userId === null || $flag->rollout_pct >= 100) {
            return true;
        }

        return ($userId % 100) < $flag->rollout_pct;
    }

    /**
     * Get all flags for a user (for frontend feature-flag endpoint).
     */
    public static function allForUser(?int $userId = null): array
    {
        $flags = Cache::remember('feature_flags:all', 60, function () {
            return static::all();
        });

        $result = [];
        foreach ($flags as $flag) {
            $result[$flag->key] = static::isEnabled($flag->key, $userId);
        }
        return $result;
    }
}
