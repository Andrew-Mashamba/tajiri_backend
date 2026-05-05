<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentCategory extends Model
{
    protected $table = 'content_categories';
    protected $primaryKey = 'slug';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['slug', 'name_en', 'name_sw'];
}
