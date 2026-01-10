<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UniversityCampus extends Model
{
    protected $table = 'university_campuses';

    protected $fillable = [
        'code',
        'name',
        'location',
        'university_id',
    ];

    public function university()
    {
        return $this->belongsTo(UniversityDetailed::class, 'university_id');
    }
}
