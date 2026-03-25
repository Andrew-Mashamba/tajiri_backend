<?php

namespace App\Providers;

use App\Services\Firebase\FirebaseLiveUpdateService;
use App\Services\TurnCredentialService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FirebaseLiveUpdateService::class);
        $this->app->singleton(TurnCredentialService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Configure rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        // Call creation: 30/min
        RateLimiter::for('calls-create', function (Request $request) {
            return Limit::perMinute(30)->by($request->input('caller_id', $request->ip()));
        });

        // Signaling relay: 200/min (high frequency for WebRTC)
        RateLimiter::for('calls-signaling', function (Request $request) {
            return Limit::perMinute(200)->by($request->input('user_id', $request->ip()));
        });

        // TURN credentials: 20/min
        RateLimiter::for('calls-turn', function (Request $request) {
            return Limit::perMinute(20)->by($request->input('user_id', $request->ip()));
        });

        // Call reactions: 20/min/user/call
        RateLimiter::for('calls-reactions', function (Request $request) {
            $key = $request->input('user_id', $request->ip()) . ':' . $request->route('id', '0');
            return Limit::perMinute(20)->by($key);
        });

        // Raise hand: 30/min/user/call
        RateLimiter::for('calls-raise-hand', function (Request $request) {
            $key = $request->input('user_id', $request->ip()) . ':' . $request->route('id', '0');
            return Limit::perMinute(30)->by($key);
        });

        // Call actions (answer, decline, end): 60/min
        RateLimiter::for('calls-actions', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        // Missed call voice message: 10/min
        RateLimiter::for('calls-voice-message', function (Request $request) {
            return Limit::perMinute(10)->by($request->input('user_id', $request->ip()));
        });

        // Scheduled calls create: 20/day (handled in controller, this is per-minute guard)
        RateLimiter::for('scheduled-calls-create', function (Request $request) {
            return Limit::perMinute(10)->by($request->input('creator_id', $request->ip()));
        });

        // Scheduled calls start: 30/min
        RateLimiter::for('scheduled-calls-start', function (Request $request) {
            return Limit::perMinute(30)->by($request->input('user_id', $request->ip()));
        });

        // Group call management: 60/min
        RateLimiter::for('calls-group', function (Request $request) {
            return Limit::perMinute(60)->by($request->input('user_id', $request->input('initiated_by', $request->ip())));
        });

        // General API rate limit
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });
    }
}
