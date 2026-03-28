<?php

namespace App\Jobs;

use App\Models\Post;
use App\Models\PostMedia;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Compose a reply (duet) or stitch video using FFmpeg.
 * 
 * Reply (Duet): Combines original + user video side-by-side, top-bottom, or PiP.
 * Stitch: Concatenates a trimmed clip of the original + user's full video sequentially.
 */
class ComposeReplyVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600; // 10 minutes

    public function __construct(
        public int $postId,
        public string $type, // 'reply' or 'stitch'
    ) {}

    public function handle(): void
    {
        $post = Post::with(['media', 'replyToPost.media', 'stitchFromPost.media'])->find($this->postId);
        if (!$post) {
            Log::warning("ComposeReplyVideo: Post {$this->postId} not found");
            return;
        }

        if ($this->type === 'reply') {
            $this->composeReply($post);
        } elseif ($this->type === 'stitch') {
            $this->composeStitch($post);
        }
    }

    private function composeReply(Post $post): void
    {
        $originalPost = $post->replyToPost;
        if (!$originalPost) {
            Log::warning("ComposeReplyVideo: Original post not found for reply {$post->id}");
            return;
        }

        $originalVideo = $originalPost->media->where('media_type', 'video')->first();
        $userVideo = $post->media->where('media_type', 'video')->first();

        if (!$originalVideo || !$userVideo) {
            Log::warning("ComposeReplyVideo: Missing video media for reply {$post->id}");
            return;
        }

        $originalPath = Storage::disk('public')->path($originalVideo->file_path);
        $userPath = Storage::disk('public')->path($userVideo->file_path);
        $outputDir = 'posts/' . $post->id;
        $outputFilename = 'composed_reply_' . time() . '.mp4';
        $outputRelPath = $outputDir . '/' . $outputFilename;
        $outputFullPath = Storage::disk('public')->path($outputRelPath);

        Storage::disk('public')->makeDirectory($outputDir);

        $layout = $post->reply_layout ?? 'side_by_side';

        // Build FFmpeg command based on layout
        switch ($layout) {
            case 'top_bottom':
                $filter = '[0:v]scale=1080:960[top];[1:v]scale=1080:960[bottom];[top][bottom]vstack';
                break;
            case 'pip':
                $filter = '[0:v]scale=1080:1920[bg];[1:v]scale=324:576[pip];[bg][pip]overlay=W-w-20:20';
                break;
            case 'side_by_side':
            default:
                $filter = '[0:v]scale=540:1920[left];[1:v]scale=540:1920[right];[left][right]hstack';
                break;
        }

        $cmd = "ffmpeg -y -i " . escapeshellarg($originalPath) 
            . " -i " . escapeshellarg($userPath)
            . " -filter_complex " . escapeshellarg($filter)
            . " -c:v libx264 -preset fast -crf 23"
            . " -c:a aac -b:a 128k"
            . " -shortest"
            . " " . escapeshellarg($outputFullPath)
            . " 2>&1";

        Log::info("ComposeReplyVideo: Running FFmpeg for reply {$post->id}: {$cmd}");
        $output = shell_exec($cmd);
        Log::info("ComposeReplyVideo: FFmpeg output: {$output}");

        if (file_exists($outputFullPath) && filesize($outputFullPath) > 0) {
            // Create new media entry for composed video
            PostMedia::create([
                'post_id' => $post->id,
                'media_type' => 'video',
                'file_path' => $outputRelPath,
                'original_filename' => $outputFilename,
                'file_size' => filesize($outputFullPath),
                'order' => 99, // After the original user video
            ]);

            Log::info("ComposeReplyVideo: Reply {$post->id} composed successfully: {$outputRelPath}");
        } else {
            Log::error("ComposeReplyVideo: FFmpeg failed for reply {$post->id}");
        }
    }

    private function composeStitch(Post $post): void
    {
        $originalPost = $post->stitchFromPost;
        if (!$originalPost) {
            Log::warning("ComposeReplyVideo: Original post not found for stitch {$post->id}");
            return;
        }

        $originalVideo = $originalPost->media->where('media_type', 'video')->first();
        $userVideo = $post->media->where('media_type', 'video')->first();

        if (!$originalVideo || !$userVideo) {
            Log::warning("ComposeReplyVideo: Missing video media for stitch {$post->id}");
            return;
        }

        $originalPath = Storage::disk('public')->path($originalVideo->file_path);
        $userPath = Storage::disk('public')->path($userVideo->file_path);
        $outputDir = 'posts/' . $post->id;
        Storage::disk('public')->makeDirectory($outputDir);

        // Step 1: Trim the original clip
        $trimStart = $post->stitch_trim_start_ms ?? 0;
        $trimEnd = $post->stitch_trim_end_ms ?? 5000;
        $trimDuration = ($trimEnd - $trimStart) / 1000.0;
        $trimStartSec = $trimStart / 1000.0;

        $clippedPath = Storage::disk('public')->path($outputDir . '/stitch_clip_' . time() . '.mp4');

        $trimCmd = "ffmpeg -y -ss {$trimStartSec} -i " . escapeshellarg($originalPath)
            . " -t {$trimDuration}"
            . " -c:v libx264 -preset fast -crf 23"
            . " -c:a aac -b:a 128k"
            . " " . escapeshellarg($clippedPath)
            . " 2>&1";

        Log::info("ComposeReplyVideo: Trimming original for stitch {$post->id}: {$trimCmd}");
        shell_exec($trimCmd);

        if (!file_exists($clippedPath) || filesize($clippedPath) == 0) {
            Log::error("ComposeReplyVideo: Trim failed for stitch {$post->id}");
            return;
        }

        // Step 2: Create concat file list
        $concatListPath = Storage::disk('public')->path($outputDir . '/concat_list.txt');
        file_put_contents($concatListPath, "file " . escapeshellarg($clippedPath) . "\nfile " . escapeshellarg($userPath) . "\n");

        // Step 3: Concatenate
        $outputFilename = 'composed_stitch_' . time() . '.mp4';
        $outputRelPath = $outputDir . '/' . $outputFilename;
        $outputFullPath = Storage::disk('public')->path($outputRelPath);

        $concatCmd = "ffmpeg -y -f concat -safe 0 -i " . escapeshellarg($concatListPath)
            . " -c:v libx264 -preset fast -crf 23"
            . " -c:a aac -b:a 128k"
            . " " . escapeshellarg($outputFullPath)
            . " 2>&1";

        Log::info("ComposeReplyVideo: Concatenating stitch {$post->id}: {$concatCmd}");
        $output = shell_exec($concatCmd);
        Log::info("ComposeReplyVideo: FFmpeg output: {$output}");

        // Cleanup temp files
        @unlink($clippedPath);
        @unlink($concatListPath);

        if (file_exists($outputFullPath) && filesize($outputFullPath) > 0) {
            PostMedia::create([
                'post_id' => $post->id,
                'media_type' => 'video',
                'file_path' => $outputRelPath,
                'original_filename' => $outputFilename,
                'file_size' => filesize($outputFullPath),
                'order' => 99,
            ]);

            Log::info("ComposeReplyVideo: Stitch {$post->id} composed successfully: {$outputRelPath}");
        } else {
            Log::error("ComposeReplyVideo: FFmpeg concat failed for stitch {$post->id}");
        }
    }
}
