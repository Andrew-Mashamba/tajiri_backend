<?php

namespace App\Console\Commands;

use App\Models\UserPresence;
use Illuminate\Console\Command;

class MarkStaleUsersOffline extends Command
{
    protected $signature = 'presence:cleanup {--minutes=5 : Minutes of inactivity before marking offline}';

    protected $description = 'Mark users as offline if they have not sent a heartbeat recently';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $count = UserPresence::markStaleOffline($minutes);

        if ($count > 0) {
            $this->info("Marked {$count} stale user(s) as offline.");
        }

        return Command::SUCCESS;
    }
}
