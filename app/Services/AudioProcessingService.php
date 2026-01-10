<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AudioProcessingService
{
    const MAX_WAVEFORM_POINTS = 100;
    const MAX_AUDIO_DURATION = 300; // 5 minutes

    /**
     * Process uploaded audio file and extract metadata
     */
    public function processAudio(UploadedFile $file, string $disk = 'public'): array
    {
        $path = $file->store('audio', $disk);
        $fullPath = Storage::disk($disk)->path($path);

        $duration = $this->getAudioDuration($fullPath);
        $waveform = $this->generateWaveform($fullPath);
        $metadata = $this->getAudioMetadata($fullPath);

        return [
            'path' => $path,
            'url' => Storage::disk($disk)->url($path),
            'duration' => $duration,
            'duration_formatted' => $this->formatDuration($duration),
            'waveform' => $waveform,
            'bitrate' => $metadata['bitrate'],
            'sample_rate' => $metadata['sample_rate'],
            'channels' => $metadata['channels'],
            'codec' => $metadata['codec'],
        ];
    }

    /**
     * Get audio duration using FFprobe
     */
    public function getAudioDuration(string $path): ?float
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
            return $this->getDurationFallback($path);
        }

        return (float) trim($output);
    }

    /**
     * Fallback duration extraction using exiftool
     */
    protected function getDurationFallback(string $path): ?float
    {
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
     * Generate waveform data points for visualization
     * Returns array of amplitude values (0-1 range)
     */
    public function generateWaveform(string $path, int $points = self::MAX_WAVEFORM_POINTS): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $duration = $this->getAudioDuration($path);
        if (!$duration || $duration <= 0) {
            return array_fill(0, $points, 0.5);
        }

        // Create temporary raw audio file
        $tempFile = sys_get_temp_dir() . '/' . Str::uuid() . '.raw';

        // Convert to mono raw PCM, downsample to reduce data
        $sampleRate = min(8000, (int)($points * 10)); // Lower sample rate for efficiency
        $command = sprintf(
            'ffmpeg -i %s -ac 1 -ar %d -f s16le -acodec pcm_s16le %s -y 2>/dev/null',
            escapeshellarg($path),
            $sampleRate,
            escapeshellarg($tempFile)
        );

        shell_exec($command);

        if (!file_exists($tempFile)) {
            // Return default waveform if ffmpeg failed
            return $this->generateDefaultWaveform($points);
        }

        $waveform = $this->extractWaveformFromRaw($tempFile, $points);

        // Clean up temp file
        @unlink($tempFile);

        return $waveform;
    }

    /**
     * Extract waveform data from raw PCM file
     */
    protected function extractWaveformFromRaw(string $rawPath, int $points): array
    {
        $fileSize = filesize($rawPath);
        if ($fileSize < 2) {
            return $this->generateDefaultWaveform($points);
        }

        // Each sample is 2 bytes (16-bit)
        $totalSamples = $fileSize / 2;
        $samplesPerPoint = max(1, (int)($totalSamples / $points));

        $waveform = [];
        $handle = fopen($rawPath, 'rb');

        if (!$handle) {
            return $this->generateDefaultWaveform($points);
        }

        for ($i = 0; $i < $points; $i++) {
            $position = $i * $samplesPerPoint * 2;

            if ($position >= $fileSize - 2) {
                break;
            }

            fseek($handle, $position);
            $data = fread($handle, $samplesPerPoint * 2);

            if ($data === false || strlen($data) < 2) {
                $waveform[] = 0.0;
                continue;
            }

            // Calculate RMS amplitude for this segment
            $samples = unpack('s*', $data);
            if (!$samples || count($samples) === 0) {
                $waveform[] = 0.0;
                continue;
            }

            $sumSquares = 0;
            foreach ($samples as $sample) {
                $sumSquares += $sample * $sample;
            }

            $rms = sqrt($sumSquares / count($samples));
            // Normalize to 0-1 range (max 16-bit value is 32768)
            $normalized = min(1.0, $rms / 32768);

            // Apply light smoothing/scaling for visual appeal
            $waveform[] = round(pow($normalized, 0.7), 3);
        }

        fclose($handle);

        // Ensure we have exactly the requested number of points
        while (count($waveform) < $points) {
            $waveform[] = 0.0;
        }

        return array_slice($waveform, 0, $points);
    }

    /**
     * Generate a default waveform pattern
     */
    protected function generateDefaultWaveform(int $points): array
    {
        $waveform = [];
        for ($i = 0; $i < $points; $i++) {
            // Generate a slight wave pattern
            $waveform[] = round(0.3 + 0.2 * sin($i * 0.3), 3);
        }
        return $waveform;
    }

    /**
     * Get audio metadata using FFprobe
     */
    public function getAudioMetadata(string $path): array
    {
        $metadata = [
            'bitrate' => null,
            'sample_rate' => null,
            'channels' => null,
            'codec' => null,
            'format' => null,
        ];

        if (!file_exists($path)) {
            return $metadata;
        }

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
            $metadata['bitrate'] = isset($data['format']['bit_rate']) ? (int) $data['format']['bit_rate'] : null;
            $metadata['format'] = $data['format']['format_name'] ?? null;
        }

        // Extract audio stream info
        foreach ($data['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? '') === 'audio') {
                $metadata['sample_rate'] = isset($stream['sample_rate']) ? (int) $stream['sample_rate'] : null;
                $metadata['channels'] = $stream['channels'] ?? null;
                $metadata['codec'] = $stream['codec_name'] ?? null;
                break;
            }
        }

        return $metadata;
    }

    /**
     * Trim audio file to specified start and end times
     */
    public function trimAudio(string $inputPath, float $start, float $end, string $disk = 'public'): ?string
    {
        if (!file_exists($inputPath)) {
            return null;
        }

        $duration = $end - $start;
        if ($duration <= 0) {
            return null;
        }

        $outputName = 'audio/trimmed/' . Str::uuid() . '.mp3';
        $outputPath = Storage::disk($disk)->path($outputName);

        // Ensure directory exists
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $command = sprintf(
            'ffmpeg -i %s -ss %f -t %f -c:a libmp3lame -q:a 2 %s -y 2>/dev/null',
            escapeshellarg($inputPath),
            $start,
            $duration,
            escapeshellarg($outputPath)
        );

        shell_exec($command);

        return file_exists($outputPath) ? $outputName : null;
    }

    /**
     * Normalize audio volume
     */
    public function normalizeVolume(string $inputPath, string $disk = 'public'): ?string
    {
        if (!file_exists($inputPath)) {
            return null;
        }

        $outputName = 'audio/normalized/' . Str::uuid() . '.mp3';
        $outputPath = Storage::disk($disk)->path($outputName);

        // Ensure directory exists
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // Two-pass normalization for best results
        // First, analyze the audio
        $analyzeCommand = sprintf(
            'ffmpeg -i %s -af "volumedetect" -vn -sn -dn -f null /dev/null 2>&1 | grep max_volume',
            escapeshellarg($inputPath)
        );

        $analyzeOutput = shell_exec($analyzeCommand);
        $maxVolume = 0;

        if (preg_match('/max_volume:\s*([-\d.]+)\s*dB/', $analyzeOutput ?? '', $matches)) {
            $maxVolume = (float) $matches[1];
        }

        // Calculate adjustment (target: -1 dB peak)
        $adjustment = -1 - $maxVolume;

        // Apply volume adjustment
        $command = sprintf(
            'ffmpeg -i %s -af "volume=%fdB" -c:a libmp3lame -q:a 2 %s -y 2>/dev/null',
            escapeshellarg($inputPath),
            $adjustment,
            escapeshellarg($outputPath)
        );

        shell_exec($command);

        return file_exists($outputPath) ? $outputName : null;
    }

    /**
     * Add fade in/out effects to audio
     */
    public function addFadeEffects(string $inputPath, float $fadeIn = 0.5, float $fadeOut = 0.5, string $disk = 'public'): ?string
    {
        if (!file_exists($inputPath)) {
            return null;
        }

        $duration = $this->getAudioDuration($inputPath);
        if (!$duration) {
            return null;
        }

        $outputName = 'audio/faded/' . Str::uuid() . '.mp3';
        $outputPath = Storage::disk($disk)->path($outputName);

        // Ensure directory exists
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $fadeOutStart = max(0, $duration - $fadeOut);

        $command = sprintf(
            'ffmpeg -i %s -af "afade=t=in:st=0:d=%f,afade=t=out:st=%f:d=%f" -c:a libmp3lame -q:a 2 %s -y 2>/dev/null',
            escapeshellarg($inputPath),
            $fadeIn,
            $fadeOutStart,
            $fadeOut,
            escapeshellarg($outputPath)
        );

        shell_exec($command);

        return file_exists($outputPath) ? $outputName : null;
    }

    /**
     * Convert audio to MP3 format for consistency
     */
    public function convertToMp3(string $inputPath, string $disk = 'public', int $quality = 2): ?string
    {
        if (!file_exists($inputPath)) {
            return null;
        }

        $outputName = 'audio/' . Str::uuid() . '.mp3';
        $outputPath = Storage::disk($disk)->path($outputName);

        // Ensure directory exists
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $command = sprintf(
            'ffmpeg -i %s -c:a libmp3lame -q:a %d %s -y 2>/dev/null',
            escapeshellarg($inputPath),
            $quality,
            escapeshellarg($outputPath)
        );

        shell_exec($command);

        return file_exists($outputPath) ? $outputName : null;
    }

    /**
     * Extract audio from video file
     */
    public function extractFromVideo(string $videoPath, string $disk = 'public'): ?string
    {
        if (!file_exists($videoPath)) {
            return null;
        }

        $outputName = 'audio/extracted/' . Str::uuid() . '.mp3';
        $outputPath = Storage::disk($disk)->path($outputName);

        // Ensure directory exists
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $command = sprintf(
            'ffmpeg -i %s -vn -c:a libmp3lame -q:a 2 %s -y 2>/dev/null',
            escapeshellarg($videoPath),
            escapeshellarg($outputPath)
        );

        shell_exec($command);

        return file_exists($outputPath) ? $outputName : null;
    }

    /**
     * Format duration in seconds to mm:ss string
     */
    public function formatDuration(?float $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        $minutes = floor($seconds / 60);
        $secs = (int)($seconds % 60);

        return sprintf('%d:%02d', $minutes, $secs);
    }

    /**
     * Check if audio duration exceeds maximum allowed
     */
    public function exceedsMaxDuration(string $path): bool
    {
        $duration = $this->getAudioDuration($path);
        return $duration !== null && $duration > self::MAX_AUDIO_DURATION;
    }

    /**
     * Get audio duration in integer seconds
     */
    public function getDurationSeconds(string $path): ?int
    {
        $duration = $this->getAudioDuration($path);
        return $duration !== null ? (int) ceil($duration) : null;
    }
}
