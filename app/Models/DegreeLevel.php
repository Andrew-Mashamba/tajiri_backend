<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DegreeLevel extends Model
{
    protected $fillable = [
        'code',
        'name',
        'duration_years',
    ];

    public function programmes()
    {
        return $this->hasMany(UniversityProgramme::class, 'level_code', 'code');
    }
}
