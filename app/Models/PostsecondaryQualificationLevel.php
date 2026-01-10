<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostsecondaryQualificationLevel extends Model
{
    protected $table = 'postsecondary_qualification_levels';

    protected $fillable = [
        'code',
        'name',
        'duration_years',
    ];

    public function programmes()
    {
        return $this->hasMany(PostsecondaryProgramme::class, 'level_code', 'code');
    }
}
