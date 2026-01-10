<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UniversityCollege extends Model
{
    protected $table = 'university_colleges';

    protected $fillable = [
        'code',
        'name',
        'type',
        'university_id',
    ];

    public function university()
    {
        return $this->belongsTo(UniversityDetailed::class, 'university_id');
    }

    public function departments()
    {
        return $this->hasMany(UniversityDepartment::class, 'college_id');
    }

    public function programmes()
    {
        return $this->hasMany(UniversityProgramme::class, 'college_id');
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
