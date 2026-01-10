<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UniversityDepartment extends Model
{
    protected $table = 'university_departments';

    protected $fillable = [
        'code',
        'name',
        'college_id',
    ];

    public function college()
    {
        return $this->belongsTo(UniversityCollege::class, 'college_id');
    }

    public function programmes()
    {
        return $this->hasMany(UniversityProgramme::class, 'department_id');
    }

    public function university()
    {
        return $this->hasOneThrough(
            UniversityDetailed::class,
            UniversityCollege::class,
            'id',
            'id',
            'college_id',
            'university_id'
        );
    }
}
