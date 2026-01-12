<?php

namespace App\Services;

use getID3;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Service for extracting metadata from audio files using getID3
 */
class AudioMetadataService
{
    private getID3 $getID3;

    public function __construct()
    {
        $this->getID3 = new getID3();
        $this->getID3->option_md5_data = false;
        $this->getID3->option_md5_data_source = false;
        $this->getID3->encoding = 'UTF-8';
    }

    /**
     * Extract all metadata from an audio file
     *
     * @param string $filePath Path to the audio file
     * @return array Extracted metadata
     */
    public function extractMetadata(string $filePath): array
    {
        try {
            $fileInfo = $this->getID3->analyze($filePath);

            // Get basic audio info
            $metadata = [
                // Duration in seconds
                'duration' => isset($fileInfo['playtime_seconds'])
                    ? (int) round($fileInfo['playtime_seconds'])
                    : null,

                // Bitrate in kbps
                'bitrate' => isset($fileInfo['bitrate'])
                    ? (int) round($fileInfo['bitrate'] / 1000)
                    : null,

                // Sample rate in Hz (e.g., 44100)
                'sample_rate' => isset($fileInfo['audio']['sample_rate'])
                    ? (int) $fileInfo['audio']['sample_rate']
                    : null,

                // Number of channels (1=mono, 2=stereo)
                'channels' => isset($fileInfo['audio']['channels'])
                    ? (int) $fileInfo['audio']['channels']
                    : null,

                // File size in bytes
                'file_size' => isset($fileInfo['filesize'])
                    ? (int) $fileInfo['filesize']
                    : null,

                // Audio codec (mp3, aac, flac, etc.)
                'codec' => $fileInfo['audio']['dataformat'] ?? null,

                // File format/container
                'file_format' => $fileInfo['fileformat'] ?? null,
            ];

            // Extract ID3 tags
            $tags = $this->extractTags($fileInfo);
            $metadata = array_merge($metadata, $tags);

            // Extract cover art if present
            $metadata['embedded_cover'] = $this->extractCoverArt($fileInfo);

            return $metadata;

        } catch (\Exception $e) {
            Log::error('Audio metadata extraction failed: ' . $e->getMessage(), [
                'file' => $filePath,
                'exception' => $e,
            ]);

            return [
                'duration' => null,
                'bitrate' => null,
                'sample_rate' => null,
                'channels' => null,
                'file_size' => filesize($filePath) ?: null,
                'codec' => null,
                'file_format' => pathinfo($filePath, PATHINFO_EXTENSION),
            ];
        }
    }

    /**
     * Extract ID3 tags from file info
     */
    private function extractTags(array $fileInfo): array
    {
        $tags = [];

        // Possible tag sources in priority order
        $tagSources = ['id3v2', 'id3v1', 'vorbiscomment', 'ape', 'quicktime'];

        foreach ($tagSources as $source) {
            if (isset($fileInfo['tags'][$source])) {
                $sourceTags = $fileInfo['tags'][$source];

                // Title
                if (!isset($tags['title']) && isset($sourceTags['title'][0])) {
                    $tags['title'] = $this->cleanString($sourceTags['title'][0]);
                }

                // Artist
                if (!isset($tags['artist']) && isset($sourceTags['artist'][0])) {
                    $tags['artist'] = $this->cleanString($sourceTags['artist'][0]);
                }

                // Album
                if (!isset($tags['album']) && isset($sourceTags['album'][0])) {
                    $tags['album'] = $this->cleanString($sourceTags['album'][0]);
                }

                // Genre
                if (!isset($tags['genre']) && isset($sourceTags['genre'][0])) {
                    $tags['genre'] = $this->cleanString($sourceTags['genre'][0]);
                }

                // Year
                if (!isset($tags['release_year'])) {
                    $year = $sourceTags['year'][0] ?? $sourceTags['date'][0] ?? null;
                    if ($year) {
                        // Extract just the year if it's a full date
                        preg_match('/(\d{4})/', $year, $matches);
                        $tags['release_year'] = isset($matches[1]) ? (int) $matches[1] : null;
                    }
                }

                // Track number
                if (!isset($tags['track_number']) && isset($sourceTags['track_number'][0])) {
                    $trackNum = $sourceTags['track_number'][0];
                    // Handle "3/12" format
                    if (strpos($trackNum, '/') !== false) {
                        $trackNum = explode('/', $trackNum)[0];
                    }
                    $tags['track_number'] = (int) $trackNum ?: null;
                }

                // Composer
                if (!isset($tags['composer']) && isset($sourceTags['composer'][0])) {
                    $tags['composer'] = $this->cleanString($sourceTags['composer'][0]);
                }

                // Publisher/Label
                if (!isset($tags['publisher'])) {
                    $publisher = $sourceTags['publisher'][0] ?? $sourceTags['label'][0] ?? null;
                    if ($publisher) {
                        $tags['publisher'] = $this->cleanString($publisher);
                    }
                }

                // Copyright
                if (!isset($tags['copyright']) && isset($sourceTags['copyright'][0])) {
                    $tags['copyright'] = $this->cleanString($sourceTags['copyright'][0]);
                }

                // ISRC (International Standard Recording Code)
                if (!isset($tags['isrc']) && isset($sourceTags['isrc'][0])) {
                    $tags['isrc'] = $this->cleanString($sourceTags['isrc'][0]);
                }

                // BPM
                if (!isset($tags['bpm']) && isset($sourceTags['bpm'][0])) {
                    $tags['bpm'] = (int) $sourceTags['bpm'][0] ?: null;
                }

                // Lyrics
                if (!isset($tags['lyrics'])) {
                    $lyrics = $sourceTags['unsynchronised_lyric'][0]
                        ?? $sourceTags['lyrics'][0]
                        ?? $sourceTags['unsyncedlyrics'][0]
                        ?? null;
                    if ($lyrics) {
                        $tags['lyrics'] = $this->cleanString($lyrics);
                    }
                }

                // Comment
                if (!isset($tags['comment']) && isset($sourceTags['comment'][0])) {
                    $tags['comment'] = $this->cleanString($sourceTags['comment'][0]);
                }
            }
        }

        return $tags;
    }

    /**
     * Extract embedded cover art from audio file
     *
     * @return array|null Array with 'data' (base64) and 'mime' type, or null
     */
    private function extractCoverArt(array $fileInfo): ?array
    {
        // Check for attached picture (ID3v2)
        if (isset($fileInfo['id3v2']['APIC'][0]['data'])) {
            return [
                'data' => base64_encode($fileInfo['id3v2']['APIC'][0]['data']),
                'mime' => $fileInfo['id3v2']['APIC'][0]['image_mime'] ?? 'image/jpeg',
            ];
        }

        // Check for comments picture
        if (isset($fileInfo['comments']['picture'][0]['data'])) {
            return [
                'data' => base64_encode($fileInfo['comments']['picture'][0]['data']),
                'mime' => $fileInfo['comments']['picture'][0]['image_mime'] ?? 'image/jpeg',
            ];
        }

        return null;
    }

    /**
     * Clean and sanitize string values
     */
    private function cleanString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Remove null bytes and trim
        $value = str_replace("\0", '', $value);
        $value = trim($value);

        // Return null for empty strings
        return $value === '' ? null : $value;
    }

    /**
     * Get supported audio formats
     */
    public static function getSupportedFormats(): array
    {
        return [
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'aac' => 'audio/aac',
            'm4a' => 'audio/mp4',
            'ogg' => 'audio/ogg',
            'flac' => 'audio/flac',
            'wma' => 'audio/x-ms-wma',
            'aiff' => 'audio/aiff',
        ];
    }

    /**
     * Validate if file is a supported audio format
     */
    public function isValidAudioFile(string $filePath): bool
    {
        try {
            $fileInfo = $this->getID3->analyze($filePath);
            return isset($fileInfo['audio']) && isset($fileInfo['playtime_seconds']);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Save embedded cover art to file
     *
     * @param array $coverData Cover data from extractCoverArt()
     * @param string $outputPath Path to save the image
     * @return bool Success
     */
    public function saveCoverArt(array $coverData, string $outputPath): bool
    {
        try {
            $imageData = base64_decode($coverData['data']);
            return file_put_contents($outputPath, $imageData) !== false;
        } catch (\Exception $e) {
            Log::error('Failed to save cover art: ' . $e->getMessage());
            return false;
        }
    }
}
