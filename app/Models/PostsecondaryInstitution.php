<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostsecondaryInstitution extends Model
{
    protected $table = 'postsecondary_institutions';

    protected $fillable = [
        'code',
        'name',
        'acronym',
        'type',
        'category',
        'region',
        'established',
        'website',
    ];

    public function campuses()
    {
        return $this->hasMany(PostsecondaryCampus::class, 'institution_id');
    }

    public function departments()
    {
        return $this->hasMany(PostsecondaryDepartment::class, 'institution_id');
    }

    public function programmes()
    {
        return $this->hasMany(PostsecondaryProgramme::class, 'institution_id');
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where('name', 'ilike', "%{$term}%")
            ->orWhere('acronym', 'ilike', "%{$term}%")
            ->orWhere('code', 'ilike', "%{$term}%");
    }

    public function scopeVocational($query)
    {
        return $query->where('category', 'vocational_training');
    }

    public function scopeTeacherTraining($query)
    {
        return $query->where('category', 'teacher_training');
    }

    public function scopeHealth($query)
    {
        return $query->where('category', 'health_medical');
    }

    public function scopeTechnical($query)
    {
        return $query->where('category', 'technical_polytechnic');
    }

    public function scopeAgricultural($query)
    {
        return $query->where('category', 'agricultural');
    }

    public function scopeGovernment($query)
    {
        return $query->where('type', 'like', 'government_%');
    }

    public function scopePrivate($query)
    {
        return $query->where('type', 'like', 'private_%');
    }

    public function scopeInRegion($query, string $region)
    {
        return $query->where('region', $region);
    }

    public function scopeInCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public static function categoryLabels(): array
    {
        return [
            'vocational_training' => 'Vocational Training (VETA)',
            'teacher_training' => 'Teacher Training Colleges',
            'health_medical' => 'Health & Medical Training',
            'technical_polytechnic' => 'Technical & Polytechnic',
            'agricultural' => 'Agricultural Institutes',
            'police_military' => 'Police & Military',
            'folk_development' => 'Folk Development Colleges',
            'business_professional' => 'Business & Professional',
        ];
    }
}
