<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;

trait ChecksFeatureFlags
{
    protected static function isFeatureEnabled(string $flag, int $userId): bool
    {
        $feature = DB::table('feature_flags')->where('flag_name', $flag)->first();
        if (!$feature) return false;
        if (!$feature->is_enabled) return false;
        return ($userId % 100) < $feature->rollout_pct;
    }
}
