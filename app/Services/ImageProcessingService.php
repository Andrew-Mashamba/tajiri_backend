<?php

namespace App\Services;

use App\Models\PostMedia;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;

/**
 * Image processing service for profile grid optimization.
 *
 * - Extracts dominant color from images (for placeholder backgrounds)
 * - Generates small grid thumbnails (~300x300px square, center-cropped)
 */
class ImageProcessingService
{
    protected ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new GdDriver());
    }

    /**
     * Process a PostMedia item: extract dominant color + generate grid thumbnail.
     */
    public function processForGrid(PostMedia $media): void
    {
        if ($media->media_type !== PostMedia::TYPE_IMAGE) {
            // For videos, process the existing thumbnail if available
            if ($media->media_type === PostMedia::TYPE_VIDEO && $media->thumbnail_path) {
                $this->processImageFile($media, $media->thumbnail_path);
            }
            return;
        }

        $this->processImageFile($media, $media->file_path);
    }

    /**
     * Process an image file path: extract color + create grid thumb.
     */
    protected function processImageFile(PostMedia $media, string $filePath): void
    {
        $fullPath = Storage::disk('public')->path($filePath);

        if (!file_exists($fullPath)) {
            \Log::warning("ImageProcessingService: File not found: " . $fullPath);
            return;
        }

        try {
            $image = $this->manager->read($fullPath);

            // 1. Extract dominant color
            $dominantColor = $this->extractDominantColor($image);

            // 2. Generate grid thumbnail (300x300, center-cropped)
            $gridThumbPath = $this->generateGridThumbnail($image, $filePath);

            // Update the media record
            $media->update([
                'dominant_color' => $dominantColor,
                'grid_thumbnail_path' => $gridThumbPath,
            ]);

        } catch (\Throwable $e) {
            \Log::error("ImageProcessingService: Failed to process " . $filePath . ": " . $e->getMessage());
        }
    }

    /**
     * Extract the dominant color from an image.
     *
     * Strategy: resize to 1x1 pixel (average color) — fast and effective.
     * Returns hex color string like '#3A5F8C'.
     */
    protected function extractDominantColor($image): string
    {
        // Clone so we don't modify the original
        $tiny = clone $image;
        $tiny->resize(1, 1);

        // Get the single pixel color
        $color = $tiny->pickColor(0, 0);
        $r = $color->red()->toInt();
        $g = $color->green()->toInt();
        $b = $color->blue()->toInt();

        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }

    /**
     * Generate a small square grid thumbnail (300x300px, center-cropped).
     *
     * Stored alongside the original in a 'grid_thumbs/' subdirectory.
     * Returns the storage-relative path.
     */
    protected function generateGridThumbnail($image, string $originalPath): string
    {
        // Build output path: posts/123/grid_thumb_filename.jpg
        $dir = dirname($originalPath);
        $basename = pathinfo($originalPath, PATHINFO_FILENAME);
        $gridThumbPath = $dir . '/grid_' . $basename . '.jpg';

        // Clone and resize to 300x300 center-cropped square
        $thumb = clone $image;
        $thumb->cover(300, 300);

        // Encode as JPEG at quality 75 (small file size, acceptable quality for grid)
        $encoded = $thumb->toJpeg(75);

        // Save to storage
        Storage::disk('public')->put($gridThumbPath, (string) $encoded);

        return $gridThumbPath;
    }

    /**
     * Batch-process all PostMedia items that don't have grid thumbnails yet.
     * Useful for backfilling existing posts.
     */
    public function backfillGridThumbnails(int $limit = 100): int
    {
        $items = PostMedia::whereNull('grid_thumbnail_path')
            ->whereIn('media_type', [PostMedia::TYPE_IMAGE, PostMedia::TYPE_VIDEO])
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();

        $processed = 0;
        foreach ($items as $media) {
            $this->processForGrid($media);
            $processed++;
        }

        return $processed;
    }
}
