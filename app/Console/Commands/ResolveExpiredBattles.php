<?php

namespace App\Console\Commands;

use App\Models\CreatorBattle;
use Illuminate\Console\Command;

class ResolveExpiredBattles extends Command
{
    protected $signature = 'flywheel:resolve-expired-battles';
    protected $description = 'Auto-complete battles that have passed their ends_at timestamp';

    public function handle(): int
    {
        $resolved = CreatorBattle::where('status', 'active')
            ->where('ends_at', '<', now())
            ->update(['status' => 'completed']);

        $this->info("Resolved {$resolved} expired battles.");
        return self::SUCCESS;
    }
}
