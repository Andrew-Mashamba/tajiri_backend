<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreatorsFundPeriod extends Model
{
    protected $table = 'creators_fund_periods';
    protected $guarded = [];

    protected $casts = [
        'period_start'                  => 'datetime',
        'period_end'                    => 'datetime',
        'settled_at'                    => 'datetime',
        'phase_1_committed_budget_tsh'  => 'float',
        'ad_revenue_tsh'                => 'float',
        'fan_funding_take_tsh'          => 'float',
        'marketplace_take_tsh'          => 'float',
        'brand_deal_take_tsh'           => 'float',
        'live_gifts_take_tsh'           => 'float',
        'ad_share_pct'                  => 'float',
        'pass_through_share_pct'        => 'float',
        'treasury_topup_tsh'            => 'float',
        'floor_tsh'                     => 'float',
        'fund_size_tsh'                 => 'float',
        'reserve_topup_tsh'             => 'float',
        'total_points'                  => 'float',
        'fund_per_point'                => 'float',
    ];

    public static function currentOpen(): ?self
    {
        return self::where('status', 'open')
            ->where('period_start', '<=', now())
            ->where('period_end', '>', now())
            ->first();
    }

    /**
     * Open the next weekly period anchored to Monday 00:00 UTC+3.
     * Strategy §1.2 fund replenishment cycle.
     */
    public static function openNextPeriod(string $phase = 'phase_1'): self
    {
        $start = now()->setTimezone('Africa/Nairobi')->startOfWeek()->setTimezone('UTC');
        $end   = $start->copy()->addWeek();
        return self::create([
            'period_start'                 => $start,
            'period_end'                   => $end,
            'status'                       => 'open',
            'phase'                        => $phase,
            'phase_1_committed_budget_tsh' => $phase === 'phase_1'
                ? config('earnings.phase_1_weekly_fund_tsh', 50_000_000)
                : null,
            'floor_tsh'                    => config('earnings.phase_1_weekly_fund_tsh', 50_000_000),
            'fund_size_tsh'                => $phase === 'phase_1'
                ? config('earnings.phase_1_weekly_fund_tsh', 50_000_000)
                : 0,
        ]);
    }

    public function points()
    {
        return $this->hasMany(CreatorsFundPoint::class, 'period_id');
    }
}
