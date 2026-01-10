<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UniversityDetailed extends Model
{
    protected $table = 'universities_detailed';

    protected $fillable = [
        'code',
        'name',
        'acronym',
        'type',
        'region',
        'established',
        'website',
        'parent_university',
    ];

    public function campuses()
    {
        return $this->hasMany(UniversityCampus::class, 'university_id');
    }

    public function colleges()
    {
        return $this->hasMany(UniversityCollege::class, 'university_id');
    }

    public function programmes()
    {
        return $this->hasMany(UniversityProgramme::class, 'university_id');
    }

    public function parentUniversity()
    {
        return $this->belongsTo(UniversityDetailed::class, 'parent_university', 'code');
    }

    public function constituentColleges()
    {
        return $this->hasMany(UniversityDetailed::class, 'parent_university', 'code');
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where('name', 'ilike', "%{$term}%")
            ->orWhere('acronym', 'ilike', "%{$term}%")
            ->orWhere('code', 'ilike', "%{$term}%");
    }

    public function scopePublicUniversities($query)
    {
        return $query->where('type', 'public_university');
    }

    public function scopePrivateUniversities($query)
    {
        return $query->where('type', 'private_university');
    }

    public function scopeColleges($query)
    {
        return $query->whereIn('type', ['public_college', 'private_college']);
    }

    public function scopeInstitutes($query)
    {
        return $query->whereIn('type', ['public_institute', 'private_institute']);
    }

    public function scopeInRegion($query, string $region)
    {
        return $query->where('region', $region);
    }

    public static function typeLabels(): array
    {
        return [
            'public_university' => 'Public University',
            'private_university' => 'Private University',
            'public_college' => 'Public College',
            'private_college' => 'Private College',
            'public_institute' => 'Public Institute',
            'private_institute' => 'Private Institute',
        ];
    }
}
