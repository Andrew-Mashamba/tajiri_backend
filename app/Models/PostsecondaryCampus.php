<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostsecondaryCampus extends Model
{
    protected $table = 'postsecondary_campuses';

    protected $fillable = [
        'code',
        'name',
        'location',
        'institution_id',
    ];

    public function institution()
    {
        return $this->belongsTo(PostsecondaryInstitution::class, 'institution_id');
    }
}
