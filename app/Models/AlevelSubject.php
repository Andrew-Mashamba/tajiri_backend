<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlevelSubject extends Model
{
    protected $table = 'alevel_subjects';

    protected $fillable = [
        'code',
        'name',
        'category',
    ];

    public function combinations()
    {
        return $this->belongsToMany(
            AlevelCombination::class,
            'alevel_combination_subjects',
            'subject_id',
            'combination_id'
        );
    }

    public function scopeScience($query)
    {
        return $query->where('category', 'science');
    }

    public function scopeSocialScience($query)
    {
        return $query->where('category', 'social_science');
    }

    public function scopeLanguage($query)
    {
        return $query->where('category', 'language');
    }

    public function scopeCompulsory($query)
    {
        return $query->where('category', 'compulsory');
    }
}
