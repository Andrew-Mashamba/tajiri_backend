<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostsecondaryProgramme extends Model
{
    protected $table = 'postsecondary_programmes';

    protected $fillable = [
        'code',
        'name',
        'level_code',
        'duration',
        'department_id',
        'institution_id',
    ];

    public function department()
    {
        return $this->belongsTo(PostsecondaryDepartment::class, 'department_id');
    }

    public function institution()
    {
        return $this->belongsTo(PostsecondaryInstitution::class, 'institution_id');
    }

    public function qualificationLevel()
    {
        return $this->belongsTo(PostsecondaryQualificationLevel::class, 'level_code', 'code');
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where('name', 'ilike', "%{$term}%")
            ->orWhere('code', 'ilike', "%{$term}%");
    }

    public function scopeOfLevel($query, string $levelCode)
    {
        return $query->where('level_code', $levelCode);
    }

    public function scopeVocational($query)
    {
        return $query->whereIn('level_code', ['NVA1', 'NVA2', 'NVA3']);
    }

    public function scopeTechnical($query)
    {
        return $query->whereIn('level_code', ['NTA4', 'NTA5', 'NTA6', 'NTA7', 'NTA8']);
    }

    public function scopeCertificate($query)
    {
        return $query->where('level_code', 'CERT');
    }

    public function scopeDiploma($query)
    {
        return $query->whereIn('level_code', ['DIP', 'NTA6']);
    }

    public function getYearsAttribute(): array
    {
        return array_map(fn($i) => "Year " . ($i + 1), range(0, $this->duration - 1));
    }
}
