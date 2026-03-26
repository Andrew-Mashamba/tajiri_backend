<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
|
| Define scheduled commands here. Run `php artisan schedule:run` via cron
| every minute to execute scheduled tasks.
|
| Cron entry: * * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
|
*/

// Publish scheduled posts every minute
Schedule::command('posts:publish-scheduled')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduled-posts.log'));

// Livestream: Transition scheduled streams to pre_live (15-30 min before)
Schedule::job(new \App\Jobs\TransitionToPreLive)
    ->everyMinute()
    ->withoutOverlapping();

// Livestream: Finalize ending streams after 5 seconds
Schedule::job(new \App\Jobs\TransitionToEnded)
    ->everyTenSeconds()
    ->withoutOverlapping();

// Livestream: Update viewer counts for live streams
Schedule::job(new \App\Jobs\UpdateViewerCount)
    ->everyFiveSeconds()
    ->withoutOverlapping();

// Messages: Mark stale users as offline (no heartbeat in 5 minutes)
Schedule::command('presence:cleanup')
    ->everyMinute()
    ->withoutOverlapping();

// Calls: Send reminders for scheduled calls within 5 minutes
Schedule::command('calls:send-reminders')
    ->everyMinute()
    ->withoutOverlapping();

// Clean up old draft files (weekly, Sunday at 3 AM)
Schedule::command('model:prune', ['--model' => 'App\Models\PostDraft'])
    ->weekly()
    ->sundays()
    ->at('03:00')
    ->withoutOverlapping();

// Flywheel Phase 1 — Scheduled jobs
Schedule::command("flywheel:update-viewer-streaks")->daily();
Schedule::command("flywheel:update-creator-streaks")->daily();
Schedule::command("flywheel:calculate-creator-scores")->weeklyOn(1, "00:00"); // Monday midnight

// Flywheel Phase 2 — Gossip thread detection
Schedule::command("gossip:detect")->everyFiveMinutes()->withoutOverlapping();

// Flywheel Phase 3 — Monthly fund distribution (1st of month)
Schedule::command("fund:distribute")->monthlyOn(1, "00:00")->withoutOverlapping();
