<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LiveStream;
use App\Services\StreamCacheService;
use App\Services\WebSocket\WebSocketBroadcaster;
use App\Events\StreamStatusChanged;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * RTMP Controller for nginx-rtmp-module callbacks.
 * Handles stream authentication, publish events, and stream end events.
 */
class RtmpController extends Controller
{
    protected StreamCacheService $cacheService;

    public function __construct(StreamCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Authenticate stream key before allowing RTMP connection.
     * Called by nginx-rtmp on_publish directive.
     *
     * POST /api/rtmp/auth
     * nginx sends: name (stream key), addr (client IP), app (application name)
     */
    public function auth(Request $request): Response
    {
        $streamKey = $request->input('name');
        $clientIp = $request->input('addr');
        $app = $request->input('app', 'live');

        Log::info('RTMP auth attempt', [
            'stream_key' => $streamKey,
            'client_ip' => $clientIp,
            'app' => $app,
        ]);

        if (empty($streamKey)) {
            Log::warning('RTMP auth failed: No stream key provided');
            return response('Unauthorized', 403);
        }

        // Find stream by key
        $stream = LiveStream::where('stream_key', $streamKey)
            ->whereIn('status', [
                LiveStream::STATUS_SCHEDULED,
                LiveStream::STATUS_PRE_LIVE,
                LiveStream::STATUS_LIVE,
            ])
            ->first();

        if (!$stream) {
            Log::warning('RTMP auth failed: Invalid or inactive stream key', [
                'stream_key' => $streamKey,
            ]);
            return response('Unauthorized', 403);
        }

        // Check if stream is not cancelled or ended
        if (in_array($stream->status, [LiveStream::STATUS_ENDED, LiveStream::STATUS_CANCELLED])) {
            Log::warning('RTMP auth failed: Stream is ended or cancelled', [
                'stream_id' => $stream->id,
                'status' => $stream->status,
            ]);
            return response('Stream ended', 403);
        }

        Log::info('RTMP auth successful', [
            'stream_id' => $stream->id,
            'user_id' => $stream->user_id,
            'status' => $stream->status,
        ]);

        // Return 2xx to allow connection
        return response('OK', 200);
    }

    /**
     * Handle stream publish start event.
     * Called by nginx-rtmp on_publish_done or after successful auth.
     * Triggers HLS transcoding and transitions stream to live.
     *
     * POST /api/rtmp/publish
     */
    public function onPublish(Request $request): Response
    {
        $streamKey = $request->input('name');
        $clientIp = $request->input('addr');

        Log::info('RTMP publish started', [
            'stream_key' => $streamKey,
            'client_ip' => $clientIp,
        ]);

        $stream = LiveStream::where('stream_key', $streamKey)->first();

        if (!$stream) {
            Log::error('RTMP publish: Stream not found', ['stream_key' => $streamKey]);
            return response('Not found', 404);
        }

        // Build playback URL first (needed before broadcasting)
        $hlsBase = config('app.hls_url', '/hls');
        $playbackPath = $hlsBase . '/' . $streamKey . '.m3u8';

        // Make it absolute if relative (ensures mobile apps get a working URL)
        if (!str_starts_with($playbackPath, 'http')) {
            $appUrl = rtrim(config('app.url', 'https://zima-uat.site:8003'), '/');
            $playbackPath = $appUrl . '/' . ltrim($playbackPath, '/');
        }

        // Update stream URLs in DB before broadcasting so the
        // status_changed event includes the playback_url
        $stream->update([
            'stream_url' => config('app.rtmp_url', 'rtmp://localhost/live') . '/' . $streamKey,
            'playback_url' => $playbackPath,
        ]);

        // Transition to live if in pre_live state
        if ($stream->status === LiveStream::STATUS_PRE_LIVE) {
            $stream->transitionTo(LiveStream::STATUS_LIVE);

            // Update cache
            $this->cacheService->addActiveStream($stream->id);
            $this->cacheService->invalidateStreamMetadata($stream->id);

            // Broadcast status change (playback_url is now set on $stream)
            event(new StreamStatusChanged($stream));
            WebSocketBroadcaster::broadcast($stream->id, 'status_changed', [
                'stream_id' => $stream->id,
                'status' => LiveStream::STATUS_LIVE,
                'started_at' => $stream->started_at?->toIso8601String(),
                'playback_url' => $stream->playback_url,
            ]);

            Log::info('Stream transitioned to live', [
                'stream_id' => $stream->id,
                'user_id' => $stream->user_id,
                'playback_url' => $stream->playback_url,
            ]);
        }

        // Trigger HLS transcoding (async)
        $this->triggerHlsTranscoding($stream);

        return response('OK', 200);
    }

    /**
     * Handle stream end event.
     * Called by nginx-rtmp on_done directive when broadcaster disconnects.
     *
     * POST /api/rtmp/done
     */
    public function done(Request $request): Response
    {
        $streamKey = $request->input('name');
        $clientIp = $request->input('addr');

        Log::info('RTMP stream ended', [
            'stream_key' => $streamKey,
            'client_ip' => $clientIp,
        ]);

        $stream = LiveStream::where('stream_key', $streamKey)->first();

        if (!$stream) {
            Log::warning('RTMP done: Stream not found', ['stream_key' => $streamKey]);
            return response('Not found', 404);
        }

        // Only transition if currently live
        if ($stream->status === LiveStream::STATUS_LIVE) {
            // Transition to ending (allows grace period for reconnection)
            $stream->transitionTo(LiveStream::STATUS_ENDING);

            // Update cache
            $this->cacheService->removeActiveStream($stream->id);
            $this->cacheService->invalidateStreamMetadata($stream->id);

            // Sync peak viewers to database
            $peakViewers = $this->cacheService->getPeakViewers($stream->id);
            if ($peakViewers > $stream->peak_viewers) {
                $stream->update(['peak_viewers' => $peakViewers]);
            }

            // Broadcast status change
            event(new StreamStatusChanged($stream));
            WebSocketBroadcaster::broadcast($stream->id, 'status_changed', [
                'stream_id' => $stream->id,
                'status' => LiveStream::STATUS_ENDING,
            ]);

            Log::info('Stream transitioned to ending', [
                'stream_id' => $stream->id,
                'duration' => $stream->started_at ? now()->diffInSeconds($stream->started_at) : 0,
            ]);
        }

        return response('OK', 200);
    }

    /**
     * Trigger HLS transcoding via FFmpeg.
     * This runs async to not block the RTMP callback.
     */
    protected function triggerHlsTranscoding(LiveStream $stream): void
    {
        $streamKey = $stream->stream_key;
        $rtmpUrl = config('app.rtmp_internal_url', 'rtmp://127.0.0.1/live') . '/' . $streamKey;
        $hlsPath = config('app.hls_path', '/var/www/html/tajiri/storage/app/public/hls');
        $outputPath = "{$hlsPath}/{$streamKey}.m3u8";

        // Ensure HLS directory exists
        if (!is_dir($hlsPath)) {
            mkdir($hlsPath, 0755, true);
        }

        // Check if transcoding script exists
        $scriptPath = base_path('scripts/transcode.sh');
        if (file_exists($scriptPath)) {
            // Use the transcode script (runs in background)
            Process::start("bash {$scriptPath} {$streamKey} >> /var/log/tajiri/transcode.log 2>&1 &");
            Log::info('HLS transcoding started via script', [
                'stream_id' => $stream->id,
                'stream_key' => $streamKey,
            ]);
        } else {
            // nginx-rtmp handles HLS natively if configured
            Log::info('HLS transcoding handled by nginx-rtmp', [
                'stream_id' => $stream->id,
                'stream_key' => $streamKey,
                'hls_path' => $outputPath,
            ]);
        }
    }

    /**
     * Get stream status for debugging/monitoring.
     *
     * GET /api/rtmp/status/{streamKey}
     */
    public function status(string $streamKey): \Illuminate\Http\JsonResponse
    {
        $stream = LiveStream::where('stream_key', $streamKey)->first();

        if (!$stream) {
            return response()->json(['error' => 'Stream not found'], 404);
        }

        $cachedViewers = $this->cacheService->getViewerCount($stream->id);
        $peakViewers = $this->cacheService->getPeakViewers($stream->id);

        return response()->json([
            'stream_id' => $stream->id,
            'status' => $stream->status,
            'started_at' => $stream->started_at?->toIso8601String(),
            'viewers_count' => $cachedViewers,
            'peak_viewers' => max($stream->peak_viewers, $peakViewers),
            'playback_url' => $stream->playback_url,
        ]);
    }

    /**
     * Handle play request for RTMP stream.
     * Called by nginx-rtmp on_play directive.
     * Currently we deny all direct RTMP playback (use HLS instead).
     *
     * POST /api/rtmp/on-play
     */
    public function onPlay(Request $request): Response
    {
        $streamKey = $request->input('name');
        $clientIp = $request->input('addr');

        Log::info('RTMP play attempt (denied - use HLS)', [
            'stream_key' => $streamKey,
            'client_ip' => $clientIp,
        ]);

        // Deny direct RTMP playback - clients should use HLS
        return response('Use HLS for playback', 403);
    }
}
