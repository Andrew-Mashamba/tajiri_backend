<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UniversityProgramme extends Model
{
    protected $table = 'university_programmes';

    protected $fillable = [
        'code',
        'name',
        'level_code',
        'duration',
        'department_id',
        'college_id',
        'university_id',
    ];

    public function department()
    {
        return $this->belongsTo(UniversityDepartment::class, 'department_id');
    }

    public function college()
    {
        return $this->belongsTo(UniversityCollege::class, 'college_id');
    }

    public function university()
    {
        return $this->belongsTo(UniversityDetailed::class, 'university_id');
    }

    public function degreeLevel()
    {
        return $this->belongsTo(DegreeLevel::class, 'level_code', 'code');
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

    public function scopeUndergraduate($query)
    {
        return $query->whereIn('level_code', ['CERT', 'DIP', 'ADV_DIP', 'BSC', 'BENG']);
    }

    public function scopePostgraduate($query)
    {
        return $query->whereIn('level_code', ['PGD', 'MSC', 'PHD', 'MD']);
    }

    public function getYearsAttribute(): array
    {
        return array_map(fn($i) => "Year " . ($i + 1), range(0, $this->duration - 1));
    }
}
