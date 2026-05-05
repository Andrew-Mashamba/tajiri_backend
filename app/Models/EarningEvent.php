<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EarningEvent extends Model
{
    protected $table = 'earning_events';

    protected $fillable = [
        'event_uid', 'occurred_at', 'post_id', 'comment_id', 'source_type', 'source_id',
        'actor_user_id', 'target_user_id', 'actor_role', 'stream', 'metric', 'raw_count',
        'rate_tsh', 'multipliers', 'gross_credit', 'platform_take', 'tra_wht_held',
        'net_to_creator', 'is_chargeable', 'charge_reason', 'funding_source',
        'settlement_status', 'cleared_at', 'reversed_at', 'reversal_reason',
        'journal_line_pending_id', 'journal_line_cleared_id', 'journal_line_reversal_id',
    ];

    protected $casts = [
        'occurred_at'    => 'datetime',
        'cleared_at'     => 'datetime',
        'reversed_at'    => 'datetime',
        'multipliers'    => 'array',
        'rate_tsh'       => 'float',
        'gross_credit'   => 'float',
        'platform_take'  => 'float',
        'tra_wht_held'   => 'float',
        'net_to_creator' => 'float',
        'is_chargeable'  => 'boolean',
    ];

    public function scopePending($q)    { return $q->where('settlement_status', 'pending'); }
    public function scopeCleared($q)    { return $q->where('settlement_status', 'cleared'); }
    public function scopeChargeable($q) { return $q->where('is_chargeable', true); }
    public function scopeForCreator($q, int $userId) { return $q->where('target_user_id', $userId); }
    public function scopeForPost($q, int $postId)    { return $q->where('post_id', $postId); }
}
