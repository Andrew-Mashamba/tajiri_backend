<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlevelSchool extends Model
{
    protected $fillable = [
        'code',
        'name',
        'region_code',
        'district_code',
        'type',
    ];

    public function combinations()
    {
        return $this->belongsToMany(
            AlevelCombination::class,
            'alevel_school_combinations',
            'school_id',
            'combination_id'
        );
    }

    public function region()
    {
        return $this->belongsTo(Region::class, 'region_code', 'code');
    }

    public function district()
    {
        return $this->belongsTo(District::class, 'district_code', 'code');
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where('name', 'ilike', "%{$term}%")
            ->orWhere('code', 'ilike', "%{$term}%");
    }

    public function scopeGovernment($query)
    {
        return $query->where('type', 'government');
    }

    public function scopePrivate($query)
    {
        return $query->where('type', 'private');
    }

    public function scopeWithCombination($query, string $combinationCode)
    {
        return $query->whereHas('combinations', function ($q) use ($combinationCode) {
            $q->where('code', $combinationCode);
        });
    }

    public function getCombinationCodesAttribute(): array
    {
        return $this->combinations->pluck('code')->toArray();
    }
}
