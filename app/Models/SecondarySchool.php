<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecondarySchool extends Model
{
    protected $fillable = [
        'code',
        'name',
        'region_code',
        'district_code',
        'type',
    ];

    public function scopeSearch($query, string $term)
    {
        return $query->where('name', 'ilike', "%{$term}%")
            ->orWhere('code', 'ilike', "%{$term}%");
    }

    public function scopeInRegion($query, string $regionCode)
    {
        return $query->where('region_code', $regionCode);
    }

    public function scopeInDistrict($query, string $districtCode)
    {
        return $query->where('district_code', $districtCode);
    }
}
