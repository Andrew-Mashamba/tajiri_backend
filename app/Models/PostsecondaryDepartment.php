<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostsecondaryDepartment extends Model
{
    protected $table = 'postsecondary_departments';

    protected $fillable = [
        'code',
        'name',
        'institution_id',
    ];

    public function institution()
    {
        return $this->belongsTo(PostsecondaryInstitution::class, 'institution_id');
    }

    public function programmes()
    {
        return $this->hasMany(PostsecondaryProgramme::class, 'department_id');
    }
}
