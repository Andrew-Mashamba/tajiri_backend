<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreatorsFundPoint extends Model
{
    protected $table = 'creators_fund_points';
    protected $guarded = [];

    protected $casts = [
        'points'        => 'float',
        'events_count'  => 'int',
        'last_event_at' => 'datetime',
        'payout_tsh'    => 'float',
    ];

    public function period()
    {
        return $this->belongsTo(CreatorsFundPeriod::class, 'period_id');
    }
}
