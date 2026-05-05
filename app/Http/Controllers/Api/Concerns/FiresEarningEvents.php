<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Services\EarningsEngine;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Reusable earnings hook helper. Wraps every event firing in try/catch
 * so an earnings failure never breaks the engagement endpoint that
 * triggered it. Used by every controller that fires earning events.
 */
trait FiresEarningEvents
{
    protected function fireEarning(Closure $build): void
    {
        try {
            EarningsEngine::recordEvent($build());
        } catch (\Throwable $e) {
            Log::warning('[Earnings] hook failed', [
                'error' => $e->getMessage(),
                'class' => static::class,
            ]);
        }
    }
}
