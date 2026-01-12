<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MusicTrack;
use App\Models\MusicArtist;
use App\Models\MusicCategory;
use App\Models\UserProfile;
use App\Services\AudioMetadataService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MusicController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);
        $genre = $request->query('genre');
        $category = $request->query('category');

        $query = MusicTrack::with('artist');

        if ($genre) {
            $query->where('genre', $genre);
        }

        if ($category) {
            $query->whereHas('categories', fn($q) => $q->where('slug', $category));
        }

        $tracks = $query->orderByDesc('uses_count')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'data' => $tracks->items(),
            'meta' => [
                'current_page' => $tracks->currentPage(),
                'last_page' => $tracks->lastPage(),
                'total' => $tracks->total(),
            ],
        ]);
    }

    public function featured(): JsonResponse
    {
        $tracks = MusicTrack::with('artist')
            ->featured()
            ->orderByDesc('uses_count')
            ->limit(20)
            ->get();

        return response()->json(['success' => true, 'data' => $tracks]);
    }

    public function trending(): JsonResponse
    {
        $tracks = MusicTrack::with('artist')
            ->trending()
            ->orderByDesc('uses_count')
            ->limit(20)
            ->get();

        return response()->json(['success' => true, 'data' => $tracks]);
    }

    public function show(int $id): JsonResponse
    {
        $track = MusicTrack::with(['artist', 'categories'])->find($id);

        if (!$track) {
            return response()->json(['success' => false, 'message' => 'Track not found'], 404);
        }

        $track->increment('plays_count');

        return response()->json(['success' => true, 'data' => $track]);
    }

    public function search(Request $request): JsonResponse
    {
        $query = $request->query('q');

        if (!$query || strlen($query) < 2) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $tracks = MusicTrack::with('artist')
            ->where('title', 'ilike', "%{$query}%")
            ->orWhereHas('artist', fn($q) => $q->where('name', 'ilike', "%{$query}%"))
            ->orderByDesc('uses_count')
            ->limit(30)
            ->get();

        return response()->json(['success' => true, 'data' => $tracks]);
    }

    public function categories(): JsonResponse
    {
        $categories = MusicCategory::orderBy('order')->get();

        return response()->json(['success' => true, 'data' => $categories]);
    }

    public function byCategory(string $slug): JsonResponse
    {
        $category = MusicCategory::where('slug', $slug)->first();

        if (!$category) {
            return response()->json(['success' => false, 'message' => 'Category not found'], 404);
        }

        $tracks = $category->tracks()
            ->with('artist')
            ->orderByDesc('uses_count')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $tracks->items(),
            'category' => $category,
            'meta' => [
                'current_page' => $tracks->currentPage(),
                'last_page' => $tracks->lastPage(),
                'total' => $tracks->total(),
            ],
        ]);
    }

    // Artists
    public function artists(Request $request): JsonResponse
    {
        $artists = MusicArtist::orderByDesc('followers_count')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $artists->items(),
            'meta' => [
                'current_page' => $artists->currentPage(),
                'last_page' => $artists->lastPage(),
                'total' => $artists->total(),
            ],
        ]);
    }

    public function artist(int $id): JsonResponse
    {
        $artist = MusicArtist::with(['tracks' => function ($q) {
            $q->orderByDesc('uses_count')->limit(20);
        }])->find($id);

        if (!$artist) {
            return response()->json(['success' => false, 'message' => 'Artist not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $artist]);
    }

    public function artistTracks(int $id, Request $request): JsonResponse
    {
        $artist = MusicArtist::find($id);

        if (!$artist) {
            return response()->json(['success' => false, 'message' => 'Artist not found'], 404);
        }

        $tracks = $artist->tracks()
            ->orderByDesc('uses_count')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $tracks->items(),
            'artist' => $artist,
            'meta' => [
                'current_page' => $tracks->currentPage(),
                'last_page' => $tracks->lastPage(),
                'total' => $tracks->total(),
            ],
        ]);
    }

    // User saved music
    public function savedMusic(int $userId): JsonResponse
    {
        $tracks = MusicTrack::with('artist')
            ->whereHas('savedBy', fn($q) => $q->where('user_id', $userId))
            ->orderByDesc('saved_music.created_at')
            ->get();

        return response()->json(['success' => true, 'data' => $tracks]);
    }

    public function saveTrack(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $track = MusicTrack::find($id);
        if (!$track) {
            return response()->json(['success' => false, 'message' => 'Track not found'], 404);
        }

        $track->savedBy()->syncWithoutDetaching([$request->user_id]);

        return response()->json(['success' => true, 'message' => 'Track saved']);
    }

    public function unsaveTrack(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $track = MusicTrack::find($id);
        if (!$track) {
            return response()->json(['success' => false, 'message' => 'Track not found'], 404);
        }

        $track->savedBy()->detach($request->user_id);

        return response()->json(['success' => true, 'message' => 'Track removed']);
    }

    // Admin endpoints for adding music
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:200',
            'artist_id' => 'required|exists:music_artists,id',
            'audio' => 'required|file|mimetypes:audio/*|max:20480',
            'cover' => 'nullable|file|image|max:5120',
            'album' => 'nullable|string|max:200',
            'duration' => 'required|integer|min:1',
            'genre' => 'nullable|string|max:50',
            'bpm' => 'nullable|integer',
            'is_explicit' => 'nullable|boolean',
            'category_ids' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $audioPath = $request->file('audio')->store('music', 'public');
            $coverPath = $request->hasFile('cover')
                ? $request->file('cover')->store('music/covers', 'public')
                : null;

            $track = MusicTrack::create([
                'title' => $request->title,
                'slug' => Str::slug($request->title) . '-' . Str::random(6),
                'artist_id' => $request->artist_id,
                'audio_path' => $audioPath,
                'cover_path' => $coverPath,
                'album' => $request->album,
                'duration' => $request->duration,
                'genre' => $request->genre,
                'bpm' => $request->bpm,
                'is_explicit' => $request->is_explicit ?? false,
            ]);

            if ($request->category_ids) {
                $track->categories()->attach($request->category_ids);
            }

            $track->load(['artist', 'categories']);

            return response()->json(['success' => true, 'data' => $track], 201);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed: ' . $e->getMessage()], 500);
        }
    }

    public function storeArtist(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:200',
            'image' => 'nullable|file|image|max:5120',
            'bio' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $imagePath = $request->hasFile('image')
            ? $request->file('image')->store('artists', 'public')
            : null;

        $artist = MusicArtist::create([
            'name' => $request->name,
            'slug' => Str::slug($request->name) . '-' . Str::random(4),
            'image_path' => $imagePath,
            'bio' => $request->bio,
        ]);

        return response()->json(['success' => true, 'data' => $artist], 201);
    }

    /**
     * Upload music with automatic metadata extraction
     * Extracts: duration, bitrate, sample_rate, channels, codec, ID3 tags, etc.
     */
    public function upload(Request $request): JsonResponse
    {
        \Log::info('=== MUSIC UPLOAD START ===');
        \Log::info('Request received', [
            'user_id' => $request->user_id,
            'title' => $request->title,
            'has_audio_file' => $request->hasFile('audio_file'),
            'has_cover_image' => $request->hasFile('cover_image'),
        ]);

        if ($request->hasFile('audio_file')) {
            $file = $request->file('audio_file');
            \Log::info('Audio file details', [
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'extension' => $file->getClientOriginalExtension(),
            ]);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'title' => 'required|string|max:200',
            'audio_file' => 'required|file|mimes:mp3,wav,aac,m4a,ogg,flac|max:51200', // 50MB max
            'cover_image' => 'nullable|file|image|max:5120', // 5MB max
            'album' => 'nullable|string|max:200',
            'genre' => 'nullable|string|max:50',
            'bpm' => 'nullable|integer|min:20|max:300',
            'is_explicit' => 'nullable|boolean',
            'category_ids' => 'nullable|string', // comma-separated IDs
        ]);

        if ($validator->fails()) {
            \Log::warning('Validation failed', ['errors' => $validator->errors()->toArray()]);
            return response()->json([
                'success' => false,
                'message' => 'Taarifa zilizoingizwa si sahihi',
                'errors' => $validator->errors()
            ], 422);
        }

        \Log::info('Validation passed');

        try {
            DB::beginTransaction();
            \Log::info('Transaction started');

            // Get or create artist profile for the user
            $user = UserProfile::find($request->user_id);
            $artist = $this->getOrCreateArtistForUser($user);
            \Log::info('Artist resolved', ['artist_id' => $artist->id, 'artist_name' => $artist->name]);

            // Store the audio file
            \Log::info('Storing audio file...');
            $audioFile = $request->file('audio_file');
            $audioPath = $audioFile->store('music', 'public');
            \Log::info('Audio file stored', ['path' => $audioPath]);

            $fullAudioPath = Storage::disk('public')->path($audioPath);

            // Extract metadata from audio file
            \Log::info('Extracting metadata...');
            $metadataService = new AudioMetadataService();
            $metadata = $metadataService->extractMetadata($fullAudioPath);
            \Log::info('Metadata extracted', [
                'duration' => $metadata['duration'] ?? null,
                'bitrate' => $metadata['bitrate'] ?? null,
            ]);

            // Handle cover image - either uploaded or extracted from audio
            $coverPath = null;
            if ($request->hasFile('cover_image')) {
                $coverPath = $request->file('cover_image')->store('music/covers', 'public');
            } elseif (!empty($metadata['embedded_cover'])) {
                // Save embedded cover art
                $coverPath = $this->saveEmbeddedCover($metadata['embedded_cover'], $request->title);
            }

            // Use extracted title if user didn't provide one, or metadata has better info
            $title = $request->title;
            if (empty($title) && !empty($metadata['title'])) {
                $title = $metadata['title'];
            }

            // Create the music track with all metadata
            $track = MusicTrack::create([
                'title' => $title,
                'slug' => Str::slug($title) . '-' . Str::random(6),
                'artist_id' => $artist->id,
                'uploaded_by' => $request->user_id,
                'audio_path' => $audioPath,
                'cover_path' => $coverPath,

                // User-provided or extracted metadata
                'album' => $request->album ?? $metadata['album'] ?? null,
                'genre' => $request->genre ?? $metadata['genre'] ?? null,
                'bpm' => $request->bpm ?? $metadata['bpm'] ?? null,
                'is_explicit' => $request->is_explicit ?? false,

                // Extracted audio technical metadata
                'duration' => $metadata['duration'] ?? 0,
                'bitrate' => $metadata['bitrate'] ?? null,
                'sample_rate' => $metadata['sample_rate'] ?? null,
                'channels' => $metadata['channels'] ?? null,
                'file_size' => $metadata['file_size'] ?? null,
                'codec' => $metadata['codec'] ?? null,
                'file_format' => $metadata['file_format'] ?? pathinfo($audioFile->getClientOriginalName(), PATHINFO_EXTENSION),

                // Extracted ID3 tag metadata
                'composer' => $metadata['composer'] ?? null,
                'publisher' => $metadata['publisher'] ?? null,
                'release_year' => $metadata['release_year'] ?? null,
                'track_number' => $metadata['track_number'] ?? null,
                'lyrics' => $metadata['lyrics'] ?? null,
                'comment' => $metadata['comment'] ?? null,
                'isrc' => $metadata['isrc'] ?? null,
                'copyright' => $metadata['copyright'] ?? null,

                // Default status
                'status' => 'approved',
            ]);

            // Attach categories if provided
            if ($request->category_ids) {
                $categoryIds = array_filter(explode(',', $request->category_ids));
                if (!empty($categoryIds)) {
                    $track->categories()->attach($categoryIds);
                }
            }

            // Update artist's track count
            $artist->increment('tracks_count');

            // Load relationships for response
            $track->load(['artist', 'categories']);

            DB::commit();
            \Log::info('=== MUSIC UPLOAD SUCCESS ===', ['track_id' => $track->id]);

            return response()->json([
                'success' => true,
                'message' => 'Muziki umepakiwa kikamilifu!',
                'data' => $track,
                'metadata_extracted' => [
                    'duration' => $metadata['duration'] ?? null,
                    'bitrate' => $metadata['bitrate'] ?? null,
                    'sample_rate' => $metadata['sample_rate'] ?? null,
                    'channels' => $metadata['channels'] ?? null,
                    'codec' => $metadata['codec'] ?? null,
                    'has_embedded_cover' => !empty($metadata['embedded_cover']),
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('=== MUSIC UPLOAD FAILED ===', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Clean up uploaded files on failure
            if (isset($audioPath)) {
                Storage::disk('public')->delete($audioPath);
            }
            if (isset($coverPath)) {
                Storage::disk('public')->delete($coverPath);
            }

            return response()->json([
                'success' => false,
                'message' => 'Imeshindikana kupakia muziki: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get or create an artist profile for a user
     */
    private function getOrCreateArtistForUser(UserProfile $user): MusicArtist
    {
        // Check if user already has an artist profile
        $artist = MusicArtist::where('user_id', $user->id)->first();

        if (!$artist) {
            // Create new artist profile linked to the user
            $artistName = $user->display_name ?? $user->username ?? 'Artist ' . $user->id;

            $artist = MusicArtist::create([
                'user_id' => $user->id,
                'name' => $artistName,
                'slug' => Str::slug($artistName) . '-' . Str::random(4),
                'image_path' => $user->avatar_path ?? null,
                'bio' => null,
                'is_verified' => false,
            ]);
        }

        return $artist;
    }

    /**
     * Save embedded cover art from audio file
     */
    private function saveEmbeddedCover(array $coverData, string $title): ?string
    {
        try {
            $extension = 'jpg';
            if (str_contains($coverData['mime'], 'png')) {
                $extension = 'png';
            } elseif (str_contains($coverData['mime'], 'webp')) {
                $extension = 'webp';
            }

            $filename = 'music/covers/' . Str::slug($title) . '-' . Str::random(8) . '.' . $extension;
            $imageData = base64_decode($coverData['data']);

            Storage::disk('public')->put($filename, $imageData);

            return $filename;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get tracks uploaded by a specific user
     */
    public function userTracks(int $userId, Request $request): JsonResponse
    {
        $page = $request->query('page', 1);

        $tracks = MusicTrack::with(['artist', 'categories'])
            ->where('uploaded_by', $userId)
            ->orderByDesc('created_at')
            ->paginate(20, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'data' => $tracks->items(),
            'meta' => [
                'current_page' => $tracks->currentPage(),
                'last_page' => $tracks->lastPage(),
                'total' => $tracks->total(),
            ],
        ]);
    }

    /**
     * Extract metadata from audio file and store it for later use
     * Returns temp_upload_id to be used when finalizing the upload
     */
    public function extractMetadata(Request $request): JsonResponse
    {
        // Increase execution time for large files
        set_time_limit(300); // 5 minutes
        ini_set('memory_limit', '256M');

        \Log::info('=== EXTRACT METADATA START ===');
        \Log::info('Request received', [
            'user_id' => $request->user_id,
            'has_audio_file' => $request->hasFile('audio_file'),
        ]);

        if ($request->hasFile('audio_file')) {
            $file = $request->file('audio_file');
            \Log::info('Audio file details', [
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'extension' => $file->getClientOriginalExtension(),
            ]);
        }

        $validator = Validator::make($request->all(), [
            'audio_file' => 'required|file|mimes:mp3,wav,aac,m4a,ogg,flac|max:51200',
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            \Log::warning('Validation failed', ['errors' => $validator->errors()->toArray()]);
            return response()->json([
                'success' => false,
                'message' => 'Faili si sahihi',
                'errors' => $validator->errors()
            ], 422);
        }

        \Log::info('Validation passed');

        try {
            $audioFile = $request->file('audio_file');

            \Log::info('Storing audio file...');
            $audioPath = $audioFile->store('music', 'public');
            \Log::info('Audio file stored', ['path' => $audioPath]);

            $fullAudioPath = Storage::disk('public')->path($audioPath);
            \Log::info('Full audio path', ['full_path' => $fullAudioPath]);

            // Extract metadata from stored file
            \Log::info('Extracting metadata...');
            $metadataService = new AudioMetadataService();
            $metadata = $metadataService->extractMetadata($fullAudioPath);
            \Log::info('Metadata extracted', [
                'duration' => $metadata['duration'] ?? null,
                'bitrate' => $metadata['bitrate'] ?? null,
                'title' => $metadata['title'] ?? null,
                'has_cover' => !empty($metadata['embedded_cover']),
            ]);

            // Format duration for display
            $durationFormatted = null;
            if ($metadata['duration']) {
                $minutes = floor($metadata['duration'] / 60);
                $seconds = $metadata['duration'] % 60;
                $durationFormatted = sprintf('%d:%02d', $minutes, $seconds);
            }

            // Format file size for display
            $fileSizeFormatted = null;
            if ($metadata['file_size']) {
                if ($metadata['file_size'] > 1048576) {
                    $fileSizeFormatted = round($metadata['file_size'] / 1048576, 1) . ' MB';
                } else {
                    $fileSizeFormatted = round($metadata['file_size'] / 1024) . ' KB';
                }
            }

            // Check if has embedded cover and save it
            $hasEmbeddedCover = !empty($metadata['embedded_cover']);
            $embeddedCoverBase64 = null;
            $coverPath = null;

            if ($hasEmbeddedCover) {
                $embeddedCoverBase64 = 'data:' . $metadata['embedded_cover']['mime'] . ';base64,' . $metadata['embedded_cover']['data'];
                // Save embedded cover art
                $coverPath = $this->saveEmbeddedCover(
                    $metadata['embedded_cover'],
                    $metadata['title'] ?? pathinfo($audioFile->getClientOriginalName(), PATHINFO_FILENAME)
                );
            }

            // Generate a unique temp upload ID
            $tempUploadId = Str::uuid()->toString();

            // Store temp upload info in cache (expires in 1 hour)
            $tempData = [
                'user_id' => $request->user_id,
                'audio_path' => $audioPath,
                'cover_path' => $coverPath,
                'original_filename' => $audioFile->getClientOriginalName(),
                'metadata' => $metadata,
                'created_at' => now()->toISOString(),
            ];

            \Cache::put("music_upload:{$tempUploadId}", $tempData, now()->addHour());
            \Log::info('Temp upload cached', ['temp_upload_id' => $tempUploadId]);
            \Log::info('=== EXTRACT METADATA SUCCESS ===');

            return response()->json([
                'success' => true,
                'message' => 'Faili imehifadhiwa na metadata imepatikana',
                'temp_upload_id' => $tempUploadId,
                'audio_url' => Storage::disk('public')->url($audioPath),
                'cover_url' => $coverPath ? Storage::disk('public')->url($coverPath) : null,
                'data' => [
                    // Basic info
                    'title' => $metadata['title'] ?? null,
                    'artist' => $metadata['artist'] ?? null,
                    'album' => $metadata['album'] ?? null,
                    'genre' => $metadata['genre'] ?? null,

                    // Technical info
                    'duration' => $metadata['duration'] ?? null,
                    'duration_formatted' => $durationFormatted,
                    'bitrate' => $metadata['bitrate'] ?? null,
                    'bitrate_formatted' => $metadata['bitrate'] ? $metadata['bitrate'] . ' kbps' : null,
                    'sample_rate' => $metadata['sample_rate'] ?? null,
                    'sample_rate_formatted' => $metadata['sample_rate'] ? ($metadata['sample_rate'] / 1000) . ' kHz' : null,
                    'channels' => $metadata['channels'] ?? null,
                    'channels_formatted' => $metadata['channels'] == 2 ? 'Stereo' : ($metadata['channels'] == 1 ? 'Mono' : null),
                    'file_size' => $metadata['file_size'] ?? null,
                    'file_size_formatted' => $fileSizeFormatted,
                    'codec' => $metadata['codec'] ?? null,
                    'file_format' => $metadata['file_format'] ?? null,

                    // Additional metadata
                    'release_year' => $metadata['release_year'] ?? null,
                    'track_number' => $metadata['track_number'] ?? null,
                    'composer' => $metadata['composer'] ?? null,
                    'publisher' => $metadata['publisher'] ?? null,
                    'bpm' => $metadata['bpm'] ?? null,
                    'lyrics' => $metadata['lyrics'] ?? null,
                    'comment' => $metadata['comment'] ?? null,
                    'isrc' => $metadata['isrc'] ?? null,
                    'copyright' => $metadata['copyright'] ?? null,

                    // Cover art
                    'has_embedded_cover' => $hasEmbeddedCover,
                    'embedded_cover_base64' => $embeddedCoverBase64,
                ],
            ]);

        } catch (\Exception $e) {
            \Log::error('=== EXTRACT METADATA FAILED ===', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Clean up stored file on failure
            if (isset($audioPath)) {
                Storage::disk('public')->delete($audioPath);
            }
            if (isset($coverPath)) {
                Storage::disk('public')->delete($coverPath);
            }

            return response()->json([
                'success' => false,
                'message' => 'Imeshindikana kusoma metadata: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Finalize music upload using temp_upload_id from extractMetadata
     * Creates the actual track record
     */
    public function finalizeUpload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'temp_upload_id' => 'required|string',
            'user_id' => 'required|exists:user_profiles,id',
            'title' => 'required|string|max:200',
            'cover_image' => 'nullable|file|image|max:5120',
            'album' => 'nullable|string|max:200',
            'genre' => 'nullable|string|max:50',
            'bpm' => 'nullable|integer|min:20|max:300',
            'is_explicit' => 'nullable|boolean',
            'category_ids' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Taarifa zilizoingizwa si sahihi',
                'errors' => $validator->errors()
            ], 422);
        }

        // Get temp upload data from cache
        $tempData = \Cache::get("music_upload:{$request->temp_upload_id}");

        if (!$tempData) {
            return response()->json([
                'success' => false,
                'message' => 'Faili ya muziki haipatikani. Tafadhali pakia tena.',
            ], 404);
        }

        // Verify user owns this upload
        if ($tempData['user_id'] != $request->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'Huna ruhusa ya kufanya hivi',
            ], 403);
        }

        try {
            DB::beginTransaction();

            // Get or create artist profile for the user
            $user = UserProfile::find($request->user_id);
            $artist = $this->getOrCreateArtistForUser($user);

            $metadata = $tempData['metadata'];
            $audioPath = $tempData['audio_path'];

            // Handle cover image - uploaded, from temp, or null
            $coverPath = $tempData['cover_path'];
            if ($request->hasFile('cover_image')) {
                // Delete old embedded cover if we're replacing it
                if ($coverPath) {
                    Storage::disk('public')->delete($coverPath);
                }
                $coverPath = $request->file('cover_image')->store('music/covers', 'public');
            }

            // Create the music track
            $track = MusicTrack::create([
                'title' => $request->title,
                'slug' => Str::slug($request->title) . '-' . Str::random(6),
                'artist_id' => $artist->id,
                'uploaded_by' => $request->user_id,
                'audio_path' => $audioPath,
                'cover_path' => $coverPath,

                // User-provided or extracted metadata
                'album' => $request->album ?? $metadata['album'] ?? null,
                'genre' => $request->genre ?? $metadata['genre'] ?? null,
                'bpm' => $request->bpm ?? $metadata['bpm'] ?? null,
                'is_explicit' => $request->is_explicit ?? false,

                // Extracted audio technical metadata
                'duration' => $metadata['duration'] ?? 0,
                'bitrate' => $metadata['bitrate'] ?? null,
                'sample_rate' => $metadata['sample_rate'] ?? null,
                'channels' => $metadata['channels'] ?? null,
                'file_size' => $metadata['file_size'] ?? null,
                'codec' => $metadata['codec'] ?? null,
                'file_format' => $metadata['file_format'] ?? pathinfo($tempData['original_filename'], PATHINFO_EXTENSION),

                // Extracted ID3 tag metadata
                'composer' => $metadata['composer'] ?? null,
                'publisher' => $metadata['publisher'] ?? null,
                'release_year' => $metadata['release_year'] ?? null,
                'track_number' => $metadata['track_number'] ?? null,
                'lyrics' => $metadata['lyrics'] ?? null,
                'comment' => $metadata['comment'] ?? null,
                'isrc' => $metadata['isrc'] ?? null,
                'copyright' => $metadata['copyright'] ?? null,

                'status' => 'approved',
            ]);

            // Attach categories if provided
            if ($request->category_ids) {
                $categoryIds = array_filter(explode(',', $request->category_ids));
                if (!empty($categoryIds)) {
                    $track->categories()->attach($categoryIds);
                }
            }

            // Update artist's track count
            $artist->increment('tracks_count');

            // Remove temp data from cache
            \Cache::forget("music_upload:{$request->temp_upload_id}");

            // Load relationships
            $track->load(['artist', 'categories']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Muziki umepakiwa kikamilifu!',
                'data' => $track,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Imeshindikana kupakia muziki: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cancel a pending music upload and clean up files
     */
    public function cancelUpload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'temp_upload_id' => 'required|string',
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $tempData = \Cache::get("music_upload:{$request->temp_upload_id}");

        if (!$tempData) {
            return response()->json(['success' => true, 'message' => 'Upload already cancelled or expired']);
        }

        // Verify user owns this upload
        if ($tempData['user_id'] != $request->user_id) {
            return response()->json(['success' => false, 'message' => 'Huna ruhusa'], 403);
        }

        // Delete files
        if (!empty($tempData['audio_path'])) {
            Storage::disk('public')->delete($tempData['audio_path']);
        }
        if (!empty($tempData['cover_path'])) {
            Storage::disk('public')->delete($tempData['cover_path']);
        }

        // Remove from cache
        \Cache::forget("music_upload:{$request->temp_upload_id}");

        return response()->json(['success' => true, 'message' => 'Upload cancelled']);
    }

    /**
     * Delete a track (only by uploader)
     */
    public function deleteTrack(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $track = MusicTrack::find($id);

        if (!$track) {
            return response()->json(['success' => false, 'message' => 'Track not found'], 404);
        }

        // Check if user is the uploader
        if ($track->uploaded_by !== (int) $request->user_id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            // Delete files
            if ($track->audio_path) {
                Storage::disk('public')->delete($track->audio_path);
            }
            if ($track->cover_path) {
                Storage::disk('public')->delete($track->cover_path);
            }

            // Decrement artist's track count
            if ($track->artist) {
                $track->artist->decrement('tracks_count');
            }

            $track->delete();

            return response()->json(['success' => true, 'message' => 'Track deleted']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to delete'], 500);
        }
    }
}
