<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThreadTitleTemplate extends Model
{
    protected $fillable = ["key", "template_en", "template_sw", "slots", "category", "tone", "is_active"];
    protected $casts = ["slots" => "array", "is_active" => "boolean"];
}
