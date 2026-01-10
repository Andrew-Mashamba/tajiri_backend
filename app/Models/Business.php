<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Business extends Model
{
    protected $fillable = [
        'code',
        'name',
        'acronym',
        'sector',
        'ownership',
        'category',
        'parent',
        'region',
        'ministry',
        'owner',
        'isin',
        'phone',
        'website',
        'address',
        'products',
        'year_established',
        'source',
        'source_url',
        'description',
    ];

    public function scopeSearch($query, string $term)
    {
        return $query->where('name', 'ilike', "%{$term}%")
            ->orWhere('acronym', 'ilike', "%{$term}%")
            ->orWhere('code', 'ilike', "%{$term}%");
    }

    public function scopeGovernment($query)
    {
        return $query->where('ownership', 'government');
    }

    public function scopePrivate($query)
    {
        return $query->where('ownership', 'private');
    }

    public function scopePublicListed($query)
    {
        return $query->where('ownership', 'public_listed');
    }

    public function scopeForeign($query)
    {
        return $query->where('ownership', 'foreign');
    }

    public function scopeInSector($query, string $sector)
    {
        return $query->where('sector', $sector);
    }

    public function scopeInCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function subsidiaries()
    {
        return $this->hasMany(Business::class, 'parent', 'code');
    }

    public function parentCompany()
    {
        return $this->belongsTo(Business::class, 'parent', 'code');
    }

    public static function ownershipLabels(): array
    {
        return [
            'government' => 'Government/Parastatal',
            'private' => 'Private Company',
            'public_listed' => 'Publicly Listed (DSE)',
            'foreign' => 'Foreign/Multinational',
        ];
    }

    public static function categoryLabels(): array
    {
        return [
            'parastatal' => 'Government Parastatal',
            'dse_listed' => 'DSE Listed (Local)',
            'dse_cross_listed' => 'DSE Cross-Listed',
            'conglomerate' => 'Conglomerate',
            'subsidiary' => 'Subsidiary',
            'multinational' => 'Multinational',
            'sme' => 'Small/Medium Enterprise',
        ];
    }

    public function scopeSme($query)
    {
        return $query->where('category', 'sme');
    }
}
