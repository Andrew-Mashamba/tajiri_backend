<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MusicTrack;
use App\Models\MusicArtist;
use App\Models\MusicCategory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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
}
