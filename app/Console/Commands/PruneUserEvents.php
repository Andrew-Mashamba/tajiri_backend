<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PruneUserEvents extends Command
{
    protected $signature = "flywheel:prune-user-events";
    protected $description = "Delete user_events older than 90 days";

    public function handle(): int
    {
        $cutoff = Carbon::now()->subDays(90);
        $deleted = DB::table("user_events")
            ->where("created_at", "<", $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} events older than 90 days.");
        return self::SUCCESS;
    }
}
