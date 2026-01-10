<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlevelCombination extends Model
{
    protected $table = 'alevel_combinations';

    protected $fillable = [
        'code',
        'name',
        'category',
        'popularity',
        'careers',
    ];

    protected $casts = [
        'careers' => 'array',
    ];

    public function subjects()
    {
        return $this->belongsToMany(
            AlevelSubject::class,
            'alevel_combination_subjects',
            'combination_id',
            'subject_id'
        );
    }

    public function schools()
    {
        return $this->belongsToMany(
            AlevelSchool::class,
            'alevel_school_combinations',
            'combination_id',
            'school_id'
        );
    }

    public function scopeScience($query)
    {
        return $query->where('category', 'science');
    }

    public function scopeBusiness($query)
    {
        return $query->where('category', 'business');
    }

    public function scopeArts($query)
    {
        return $query->where('category', 'arts');
    }

    public function scopePopular($query)
    {
        return $query->where('popularity', 'high');
    }

    public function getSubjectCodesAttribute(): array
    {
        return $this->subjects->pluck('code')->toArray();
    }
}
