<?php

namespace Database\Seeders;

use App\Models\CreatorsFundPeriod;
use Illuminate\Database\Seeder;

/**
 * Opens the first Creators Fund settlement period at deploy time so
 * the engine has a period to accrue points into starting from event #1.
 */
class CreatorsFundInitialPeriodSeeder extends Seeder
{
    public function run(): void
    {
        if (CreatorsFundPeriod::currentOpen()) {
            return;
        }
        CreatorsFundPeriod::openNextPeriod(config('earnings.phase', 'phase_1'));
    }
}
