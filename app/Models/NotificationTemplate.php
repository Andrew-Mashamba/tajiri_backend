<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    protected $fillable = ["key", "template_en", "template_sw", "slots", "category", "max_per_day", "priority", "is_active"];
    protected $casts = ["slots" => "array", "is_active" => "boolean"];
}
