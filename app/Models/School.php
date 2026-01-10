<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class School extends Model
{
    protected $fillable = [
        'code',
        'name',
        'region',
        'region_code',
        'district',
        'district_code',
        'type',
    ];

    public function scopeInRegion($query, string $region)
    {
        return $query->where('region', $region);
    }

    public function scopeInDistrict($query, string $districtCode)
    {
        return $query->where('district_code', $districtCode);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where('name', 'ilike', "%{$term}%")
            ->orWhere('code', 'ilike', "%{$term}%");
    }
}
