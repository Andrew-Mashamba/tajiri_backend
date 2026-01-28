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

// Clean up old draft files (weekly, Sunday at 3 AM)
Schedule::command('model:prune', ['--model' => 'App\Models\PostDraft'])
    ->weekly()
    ->sundays()
    ->at('03:00')
    ->withoutOverlapping();
