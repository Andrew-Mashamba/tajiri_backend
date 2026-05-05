<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DecayInterestWeights extends Command
{
    protected $signature = "flywheel:decay-interest-weights";
    protected $description = "Apply 5% daily decay to interest weights, prune low values";

    public function handle(): int
    {
        if (!\Schema::hasTable("user_interests")) {
            $this->info("No user_interests table found, skipping.");
            return self::SUCCESS;
        }

        $decayed = DB::table("user_interests")
            ->update(["weight" => DB::raw("weight * 0.95")]);

        $pruned = DB::table("user_interests")
            ->where("weight", "<", 0.01)
            ->delete();

        $this->info("Decayed {$decayed} weights, pruned {$pruned} below threshold.");
        return self::SUCCESS;
    }
}
