<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class University extends Model
{
    protected $fillable = [
        'code',
        'name',
        'acronym',
        'region',
        'ownership',
        'category',
        'type_label',
        'parent',
        'status',
    ];

    public function scopeSearch($query, string $term)
    {
        return $query->where('name', 'ilike', "%{$term}%")
            ->orWhere('acronym', 'ilike', "%{$term}%")
            ->orWhere('code', 'ilike', "%{$term}%");
    }

    public function scopePublic($query)
    {
        return $query->where('ownership', 'public');
    }

    public function scopePrivate($query)
    {
        return $query->where('ownership', 'private');
    }

    public function scopeInCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public static function categoryLabels(): array
    {
        return [
            'public_universities' => 'Public Universities',
            'private_universities' => 'Private Universities',
            'public_university_colleges' => 'Public University Colleges',
            'private_university_colleges' => 'Private University Colleges',
            'university_institutes_centers' => 'University Institutes & Centres',
        ];
    }
}
