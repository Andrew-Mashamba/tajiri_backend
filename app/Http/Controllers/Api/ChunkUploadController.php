<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChunkUpload;
use App\Models\Clip;
use App\Models\ClipHashtag;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class ChunkUploadController extends Controller
{
    // Default chunk size: 5MB
    const DEFAULT_CHUNK_SIZE = 5 * 1024 * 1024;

    // Upload expiration: 24 hours
    const UPLOAD_EXPIRATION_HOURS = 24;

    /**
     * Initialize a new resumable upload session
     *
     * POST /uploads/init
     */
    public function initUpload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'filename' => 'required|string|max:255',
            'file_size' => 'required|integer|min:1',
            'mime_type' => 'required|string|in:video/mp4,video/quicktime,video/x-msvideo,video/x-matroska,video/webm,video/3gpp',
            'chunk_size' => 'nullable|integer|min:1048576|max:52428800', // 1MB - 50MB

            // Optional clip metadata
            'caption' => 'nullable|string|max:2000',
            'hashtags' => 'nullable|array',
            'mentions' => 'nullable|array',
            'location_name' => 'nullable|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'privacy' => 'nullable|in:public,friends,private',
            'allow_comments' => 'nullable|boolean',
            'allow_duet' => 'nullable|boolean',
            'allow_stitch' => 'nullable|boolean',
            'allow_download' => 'nullable|boolean',
            'music_id' => 'nullable|exists:music_tracks,id',
            'music_start' => 'nullable|integer',
            'original_clip_id' => 'nullable|exists:clips,id',
            'clip_type' => 'nullable|in:original,duet,stitch',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $uploadId = ChunkUpload::generateUploadId();
            $chunkSize = $request->chunk_size ?? self::DEFAULT_CHUNK_SIZE;
            $totalChunks = (int) ceil($request->file_size / $chunkSize);

            // Create temp directory for chunks
            $tempDir = 'uploads/temp/' . $uploadId;
            Storage::disk('local')->makeDirectory($tempDir);

            $upload = ChunkUpload::create([
                'upload_id' => $uploadId,
                'user_id' => $request->user_id,
                'original_filename' => $request->filename,
                'mime_type' => $request->mime_type,
                'total_size' => $request->file_size,
                'total_chunks' => $totalChunks,
                'chunk_size' => $chunkSize,
                'temp_directory' => $tempDir,
                'completed_chunks' => [],

                // Clip metadata
                'caption' => $request->caption,
                'hashtags' => $request->hashtags,
                'mentions' => $request->mentions,
                'location_name' => $request->location_name,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'privacy' => $request->privacy ?? 'public',
                'allow_comments' => $request->allow_comments ?? true,
                'allow_duet' => $request->allow_duet ?? true,
                'allow_stitch' => $request->allow_stitch ?? true,
                'allow_download' => $request->allow_download ?? true,
                'music_id' => $request->music_id,
                'music_start' => $request->music_start,
                'original_clip_id' => $request->original_clip_id,
                'clip_type' => $request->clip_type ?? 'original',

                'status' => 'pending',
                'started_at' => now(),
                'expires_at' => now()->addHours(self::UPLOAD_EXPIRATION_HOURS),
            ]);

            Log::info("Resumable upload initialized", [
                'upload_id' => $uploadId,
                'user_id' => $request->user_id,
                'file_size' => $request->file_size,
                'total_chunks' => $totalChunks,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'upload_id' => $uploadId,
                    'chunk_size' => $chunkSize,
                    'total_chunks' => $totalChunks,
                    'expires_at' => $upload->expires_at->toIso8601String(),
                ],
            ], 201);

        } catch (\Exception $e) {
            Log::error("Failed to initialize upload", ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to initialize upload: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload a single chunk
     *
     * POST /uploads/{uploadId}/chunk
     */
    public function uploadChunk(string $uploadId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'chunk_number' => 'required|integer|min:0',
            'chunk' => 'required|file',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $upload = ChunkUpload::where('upload_id', $uploadId)->first();

        if (!$upload) {
            return response()->json([
                'success' => false,
                'message' => 'Upload session not found'
            ], 404);
        }

        if ($upload->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Upload already completed'
            ], 400);
        }

        if ($upload->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'Upload was cancelled'
            ], 400);
        }

        if ($upload->expires_at < now()) {
            $upload->update(['status' => 'expired']);
            return response()->json([
                'success' => false,
                'message' => 'Upload session expired'
            ], 410);
        }

        $chunkNumber = $request->chunk_number;

        // Validate chunk number
        if ($chunkNumber >= $upload->total_chunks) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid chunk number'
            ], 400);
        }

        // Check if chunk already uploaded (idempotent)
        if ($upload->isChunkUploaded($chunkNumber)) {
            return response()->json([
                'success' => true,
                'message' => 'Chunk already uploaded',
                'data' => [
                    'chunk_number' => $chunkNumber,
                    'uploaded_chunks' => $upload->uploaded_chunks,
                    'total_chunks' => $upload->total_chunks,
                    'progress' => $upload->progress,
                    'is_complete' => $upload->isComplete(),
                ],
            ]);
        }

        try {
            // Save chunk to temp directory
            $chunkFile = $request->file('chunk');
            $chunkPath = $upload->temp_directory . '/chunk_' . str_pad($chunkNumber, 6, '0', STR_PAD_LEFT);
            $chunkFile->storeAs('', $chunkPath, 'local');

            $chunkSize = $chunkFile->getSize();

            // Update upload status
            if ($upload->status === 'pending') {
                $upload->update(['status' => 'uploading']);
            }

            // Mark chunk as uploaded
            $upload->markChunkUploaded($chunkNumber, $chunkSize);
            $upload->refresh();

            Log::info("Chunk uploaded", [
                'upload_id' => $uploadId,
                'chunk_number' => $chunkNumber,
                'uploaded_chunks' => $upload->uploaded_chunks,
                'total_chunks' => $upload->total_chunks,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'chunk_number' => $chunkNumber,
                    'uploaded_chunks' => $upload->uploaded_chunks,
                    'total_chunks' => $upload->total_chunks,
                    'uploaded_bytes' => $upload->uploaded_bytes,
                    'total_bytes' => $upload->total_size,
                    'progress' => $upload->progress,
                    'is_complete' => $upload->isComplete(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to upload chunk", [
                'upload_id' => $uploadId,
                'chunk_number' => $chunkNumber,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload chunk: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get upload status
     *
     * GET /uploads/{uploadId}/status
     */
    public function getStatus(string $uploadId): JsonResponse
    {
        $upload = ChunkUpload::where('upload_id', $uploadId)->first();

        if (!$upload) {
            return response()->json([
                'success' => false,
                'message' => 'Upload session not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'upload_id' => $upload->upload_id,
                'status' => $upload->status,
                'total_chunks' => $upload->total_chunks,
                'uploaded_chunks' => $upload->uploaded_chunks,
                'completed_chunks' => $upload->completed_chunks,
                'missing_chunks' => $upload->getMissingChunks(),
                'uploaded_bytes' => $upload->uploaded_bytes,
                'total_bytes' => $upload->total_size,
                'progress' => $upload->progress,
                'is_complete' => $upload->isComplete(),
                'expires_at' => $upload->expires_at?->toIso8601String(),
                'final_path' => $upload->final_path,
                'error_message' => $upload->error_message,
            ],
        ]);
    }

    /**
     * Complete the upload and create the clip
     *
     * POST /uploads/{uploadId}/complete
     */
    public function completeUpload(string $uploadId): JsonResponse
    {
        $upload = ChunkUpload::where('upload_id', $uploadId)->first();

        if (!$upload) {
            return response()->json([
                'success' => false,
                'message' => 'Upload session not found'
            ], 404);
        }

        if ($upload->status === 'completed') {
            // Return existing clip
            $clip = Clip::where('video_path', $upload->final_path)->first();
            return response()->json([
                'success' => true,
                'message' => 'Upload already completed',
                'data' => $clip?->load(['user:id,first_name,last_name,username,profile_photo_path', 'music.artist']),
            ]);
        }

        if (!$upload->isComplete()) {
            return response()->json([
                'success' => false,
                'message' => 'Upload not complete',
                'missing_chunks' => $upload->getMissingChunks(),
            ], 400);
        }

        try {
            $upload->update(['status' => 'processing']);

            // Merge chunks into final file
            $finalPath = $this->mergeChunks($upload);

            // Create the clip
            $clip = Clip::create([
                'user_id' => $upload->user_id,
                'video_path' => $finalPath,
                'caption' => $upload->caption,
                'duration' => $this->getVideoDuration($finalPath),
                'music_id' => $upload->music_id,
                'music_start' => $upload->music_start,
                'hashtags' => $upload->hashtags,
                'mentions' => $upload->mentions,
                'location_name' => $upload->location_name,
                'latitude' => $upload->latitude,
                'longitude' => $upload->longitude,
                'privacy' => $upload->privacy,
                'allow_comments' => $upload->allow_comments,
                'allow_duet' => $upload->allow_duet,
                'allow_stitch' => $upload->allow_stitch,
                'allow_download' => $upload->allow_download,
                'original_clip_id' => $upload->original_clip_id,
                'clip_type' => $upload->clip_type,
                'status' => 'published',
            ]);

            // Process hashtags
            if ($upload->hashtags) {
                foreach ($upload->hashtags as $tag) {
                    $hashtag = ClipHashtag::firstOrCreate(
                        ['tag' => strtolower(trim($tag, '#'))],
                        ['tag' => strtolower(trim($tag, '#'))]
                    );
                    $hashtag->increment('clips_count');
                    $clip->hashtagRelations()->attach($hashtag->id);
                }
            }

            // Update music uses count
            if ($upload->music_id && $clip->music) {
                $clip->music->incrementUses();
            }

            // Update upload status
            $upload->update([
                'status' => 'completed',
                'final_path' => $finalPath,
                'completed_at' => now(),
            ]);

            // Clean up temp files
            $this->cleanupTempFiles($upload);

            $clip->load(['user:id,first_name,last_name,username,profile_photo_path', 'music.artist']);

            Log::info("Upload completed, clip created", [
                'upload_id' => $uploadId,
                'clip_id' => $clip->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Video uploaded successfully',
                'data' => $clip,
            ], 201);

        } catch (\Exception $e) {
            Log::error("Failed to complete upload", [
                'upload_id' => $uploadId,
                'error' => $e->getMessage()
            ]);

            $upload->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to complete upload: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel an upload
     *
     * POST /uploads/{uploadId}/cancel
     */
    public function cancelUpload(string $uploadId): JsonResponse
    {
        $upload = ChunkUpload::where('upload_id', $uploadId)->first();

        if (!$upload) {
            return response()->json([
                'success' => false,
                'message' => 'Upload session not found'
            ], 404);
        }

        if ($upload->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot cancel completed upload'
            ], 400);
        }

        // Clean up temp files
        $this->cleanupTempFiles($upload);

        $upload->update(['status' => 'cancelled']);

        return response()->json([
            'success' => true,
            'message' => 'Upload cancelled'
        ]);
    }

    /**
     * Get user's resumable uploads
     *
     * GET /uploads/resumable
     */
    public function getResumableUploads(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $uploads = ChunkUpload::resumable($request->user_id)->get();

        $data = $uploads->map(function ($upload) {
            return [
                'upload_id' => $upload->upload_id,
                'filename' => $upload->original_filename,
                'total_size' => $upload->total_size,
                'uploaded_bytes' => $upload->uploaded_bytes,
                'progress' => $upload->progress,
                'status' => $upload->status,
                'caption' => $upload->caption,
                'expires_at' => $upload->expires_at->toIso8601String(),
                'updated_at' => $upload->updated_at->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Merge all chunks into a single file
     */
    private function mergeChunks(ChunkUpload $upload): string
    {
        $tempDir = $upload->temp_directory;
        $extension = pathinfo($upload->original_filename, PATHINFO_EXTENSION);
        $finalFilename = 'clip_' . time() . '_' . uniqid() . '.' . $extension;
        $finalPath = 'clips/' . $upload->user_id . '/' . $finalFilename;

        // Ensure directory exists
        Storage::disk('public')->makeDirectory('clips/' . $upload->user_id);

        // Create final file
        $finalFullPath = Storage::disk('public')->path($finalPath);
        $finalFile = fopen($finalFullPath, 'wb');

        // Merge chunks in order
        for ($i = 0; $i < $upload->total_chunks; $i++) {
            $chunkPath = $tempDir . '/chunk_' . str_pad($i, 6, '0', STR_PAD_LEFT);
            $chunkContent = Storage::disk('local')->get($chunkPath);
            fwrite($finalFile, $chunkContent);
        }

        fclose($finalFile);

        return $finalPath;
    }

    /**
     * Clean up temporary chunk files
     */
    private function cleanupTempFiles(ChunkUpload $upload): void
    {
        try {
            Storage::disk('local')->deleteDirectory($upload->temp_directory);
        } catch (\Exception $e) {
            Log::warning("Failed to cleanup temp files", [
                'upload_id' => $upload->upload_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get video duration (placeholder - use FFmpeg in production)
     */
    private function getVideoDuration(string $path): int
    {
        // In production, use FFmpeg to get actual duration
        // For now, return placeholder
        return 15;
    }

    /**
     * Clean up expired uploads (run via scheduled command)
     */
    public static function cleanupExpiredUploads(): int
    {
        $expired = ChunkUpload::expired()->get();
        $count = 0;

        foreach ($expired as $upload) {
            try {
                Storage::disk('local')->deleteDirectory($upload->temp_directory);
                $upload->update(['status' => 'expired']);
                $count++;
            } catch (\Exception $e) {
                Log::error("Failed to cleanup expired upload", [
                    'upload_id' => $upload->upload_id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $count;
    }
}
