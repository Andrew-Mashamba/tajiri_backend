<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlevelFormLevel extends Model
{
    protected $table = 'alevel_form_levels';

    protected $fillable = [
        'code',
        'name',
        'year',
    ];
}
