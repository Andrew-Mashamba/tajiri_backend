<?php

namespace App\Http\Middleware;

use App\Models\UserPresence;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UpdatePresence
{
    /**
     * Update presence for authenticated API requests.
     * Throttled to once per minute per user to avoid excessive DB writes.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Get user_id from request (query or body) since this app uses user_id params
        $userId = $request->input('user_id') ?? $request->query('user_id');

        if ($userId) {
            $cacheKey = "presence_updated:{$userId}";

            // Only update once per minute
            if (!\Illuminate\Support\Facades\Cache::has($cacheKey)) {
                UserPresence::heartbeat((int) $userId);
                \Illuminate\Support\Facades\Cache::put($cacheKey, true, 60);
            }
        }

        return $response;
    }
}
