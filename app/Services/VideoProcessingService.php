<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoProcessingService
{
    const SHORT_VIDEO_MAX_DURATION = 60; // seconds

    /**
     * Process uploaded video and extract metadata
     */
    public function processVideo(UploadedFile $file, string $disk = 'public'): array
    {
        $path = $file->store('videos', $disk);
        $fullPath = Storage::disk($disk)->path($path);

        $duration = $this->getVideoDuration($fullPath);
        $thumbnail = $this->generateThumbnail($fullPath, $disk);
        $dimensions = $this->getVideoDimensions($fullPath);

        return [
            'path' => $path,
            'url' => Storage::disk($disk)->url($path),
            'duration' => $duration,
            'thumbnail' => $thumbnail,
            'thumbnail_url' => $thumbnail ? Storage::disk($disk)->url($thumbnail) : null,
            'width' => $dimensions['width'],
            'height' => $dimensions['height'],
            'is_short' => $duration <= self::SHORT_VIDEO_MAX_DURATION,
            'is_vertical' => $dimensions['height'] > $dimensions['width'],
            'aspect_ratio' => $this->calculateAspectRatio($dimensions['width'], $dimensions['height']),
        ];
    }

    /**
     * Get video duration using FFprobe
     */
    public function getVideoDuration(string $path): ?float
    {
        if (!file_exists($path)) {
            return null;
        }

        $command = sprintf(
            'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>/dev/null',
            escapeshellarg($path)
        );

        $output = shell_exec($command);

        if ($output === null || trim($output) === '') {
            // Fallback: try using PHP's getID3 if available
            return $this->getDurationFallback($path);
        }

        return (float) trim($output);
    }

    /**
     * Fallback duration extraction using getID3
     */
    protected function getDurationFallback(string $path): ?float
    {
        // Try using exiftool if available
        $command = sprintf(
            'exiftool -Duration -n -s3 %s 2>/dev/null',
            escapeshellarg($path)
        );

        $output = shell_exec($command);

        if ($output !== null && trim($output) !== '') {
            return (float) trim($output);
        }

        return null;
    }

    /**
     * Get video dimensions using FFprobe
     */
    public function getVideoDimensions(string $path): array
    {
        $defaults = ['width' => 0, 'height' => 0];

        if (!file_exists($path)) {
            return $defaults;
        }

        $command = sprintf(
            'ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of csv=s=x:p=0 %s 2>/dev/null',
            escapeshellarg($path)
        );

        $output = shell_exec($command);

        if ($output === null || trim($output) === '') {
            return $defaults;
        }

        $parts = explode('x', trim($output));

        if (count($parts) !== 2) {
            return $defaults;
        }

        return [
            'width' => (int) $parts[0],
            'height' => (int) $parts[1],
        ];
    }

    /**
     * Generate thumbnail from video
     */
    public function generateThumbnail(string $videoPath, string $disk = 'public', float $timestamp = 0.0): ?string
    {
        if (!file_exists($videoPath)) {
            return null;
        }

        $thumbnailName = 'thumbnails/' . Str::uuid() . '.jpg';
        $thumbnailPath = Storage::disk($disk)->path($thumbnailName);

        // Ensure thumbnails directory exists
        $thumbnailDir = dirname($thumbnailPath);
        if (!is_dir($thumbnailDir)) {
            mkdir($thumbnailDir, 0755, true);
        }

        // Use smart thumbnail position (10% into video or specified timestamp)
        $duration = $this->getVideoDuration($videoPath);
        if ($timestamp === 0.0 && $duration) {
            $timestamp = min($duration * 0.1, 3.0); // 10% into video or max 3 seconds
        }

        $command = sprintf(
            'ffmpeg -ss %f -i %s -vframes 1 -vf "scale=480:-1" -q:v 2 %s -y 2>/dev/null',
            $timestamp,
            escapeshellarg($videoPath),
            escapeshellarg($thumbnailPath)
        );

        shell_exec($command);

        if (!file_exists($thumbnailPath)) {
            // Try at the beginning if first attempt failed
            $command = sprintf(
                'ffmpeg -i %s -vframes 1 -vf "scale=480:-1" -q:v 2 %s -y 2>/dev/null',
                escapeshellarg($videoPath),
                escapeshellarg($thumbnailPath)
            );
            shell_exec($command);
        }

        return file_exists($thumbnailPath) ? $thumbnailName : null;
    }

    /**
     * Generate multiple thumbnails for preview
     */
    public function generatePreviewThumbnails(string $videoPath, string $disk = 'public', int $count = 5): array
    {
        $thumbnails = [];
        $duration = $this->getVideoDuration($videoPath);

        if (!$duration || $duration <= 0) {
            return $thumbnails;
        }

        $interval = $duration / ($count + 1);

        for ($i = 1; $i <= $count; $i++) {
            $timestamp = $interval * $i;
            $thumbnail = $this->generateThumbnail($videoPath, $disk, $timestamp);
            if ($thumbnail) {
                $thumbnails[] = [
                    'path' => $thumbnail,
                    'url' => Storage::disk($disk)->url($thumbnail),
                    'timestamp' => $timestamp,
                ];
            }
        }

        return $thumbnails;
    }

    /**
     * Calculate aspect ratio string
     */
    protected function calculateAspectRatio(int $width, int $height): string
    {
        if ($width <= 0 || $height <= 0) {
            return '1:1';
        }

        $gcd = $this->gcd($width, $height);
        $ratioW = $width / $gcd;
        $ratioH = $height / $gcd;

        // Simplify common ratios
        $ratio = $width / $height;

        if (abs($ratio - 16/9) < 0.01) return '16:9';
        if (abs($ratio - 9/16) < 0.01) return '9:16';
        if (abs($ratio - 4/3) < 0.01) return '4:3';
        if (abs($ratio - 3/4) < 0.01) return '3:4';
        if (abs($ratio - 1) < 0.01) return '1:1';

        return "{$ratioW}:{$ratioH}";
    }

    /**
     * Greatest common divisor
     */
    protected function gcd(int $a, int $b): int
    {
        return $b === 0 ? $a : $this->gcd($b, $a % $b);
    }

    /**
     * Check if video is a short (≤60 seconds)
     */
    public function isShortVideo(string $path): bool
    {
        $duration = $this->getVideoDuration($path);
        return $duration !== null && $duration <= self::SHORT_VIDEO_MAX_DURATION;
    }

    /**
     * Check if video is vertical (portrait mode)
     */
    public function isVerticalVideo(string $path): bool
    {
        $dimensions = $this->getVideoDimensions($path);
        return $dimensions['height'] > $dimensions['width'];
    }

    /**
     * Compress video for mobile optimization
     */
    public function compressForMobile(string $inputPath, string $disk = 'public'): ?string
    {
        if (!file_exists($inputPath)) {
            return null;
        }

        $outputName = 'videos/compressed/' . Str::uuid() . '.mp4';
        $outputPath = Storage::disk($disk)->path($outputName);

        // Ensure directory exists
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // Compress to 720p max, optimized for mobile
        $command = sprintf(
            'ffmpeg -i %s -vf "scale=720:-2" -c:v libx264 -preset fast -crf 28 -c:a aac -b:a 128k %s -y 2>/dev/null',
            escapeshellarg($inputPath),
            escapeshellarg($outputPath)
        );

        shell_exec($command);

        return file_exists($outputPath) ? $outputName : null;
    }

    /**
     * Extract video metadata
     */
    public function getVideoMetadata(string $path): array
    {
        $metadata = [
            'duration' => null,
            'width' => 0,
            'height' => 0,
            'bitrate' => null,
            'codec' => null,
            'fps' => null,
        ];

        if (!file_exists($path)) {
            return $metadata;
        }

        // Get full probe data
        $command = sprintf(
            'ffprobe -v quiet -print_format json -show_format -show_streams %s 2>/dev/null',
            escapeshellarg($path)
        );

        $output = shell_exec($command);

        if ($output === null) {
            return $metadata;
        }

        $data = json_decode($output, true);

        if (!$data) {
            return $metadata;
        }

        // Extract format info
        if (isset($data['format'])) {
            $metadata['duration'] = isset($data['format']['duration']) ? (float) $data['format']['duration'] : null;
            $metadata['bitrate'] = isset($data['format']['bit_rate']) ? (int) $data['format']['bit_rate'] : null;
        }

        // Extract video stream info
        foreach ($data['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? '') === 'video') {
                $metadata['width'] = $stream['width'] ?? 0;
                $metadata['height'] = $stream['height'] ?? 0;
                $metadata['codec'] = $stream['codec_name'] ?? null;

                // Calculate FPS
                if (isset($stream['r_frame_rate'])) {
                    $parts = explode('/', $stream['r_frame_rate']);
                    if (count($parts) === 2 && $parts[1] > 0) {
                        $metadata['fps'] = round($parts[0] / $parts[1], 2);
                    }
                }

                break;
            }
        }

        return $metadata;
    }
}
