<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Photo;
use App\Models\PhotoAlbum;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class AlbumController extends Controller
{
    /**
     * Get user's albums.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User ID is required',
            ], 400);
        }

        $albums = PhotoAlbum::where('user_id', $userId)
            ->with('coverPhoto:id,thumbnail_path,file_path')
            ->withCount('photos')
            ->orderBy('is_system_album', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $albums,
        ]);
    }

    /**
     * Create a new album.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'privacy' => 'in:public,friends,private',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $album = PhotoAlbum::create([
            'user_id' => $request->user_id,
            'name' => $request->name,
            'description' => $request->description,
            'privacy' => $request->privacy ?? 'public',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Album created successfully',
            'data' => $album,
        ], 201);
    }

    /**
     * Get a single album with its photos.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $album = PhotoAlbum::with(['coverPhoto:id,thumbnail_path,file_path', 'user:id,first_name,last_name,username'])
            ->withCount('photos')
            ->find($id);

        if (!$album) {
            return response()->json([
                'success' => false,
                'message' => 'Album not found',
            ], 404);
        }

        // Get photos with pagination
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);

        $photos = Photo::where('album_id', $id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'data' => [
                'album' => $album,
                'photos' => $photos->items(),
            ],
            'meta' => [
                'current_page' => $photos->currentPage(),
                'last_page' => $photos->lastPage(),
                'per_page' => $photos->perPage(),
                'total' => $photos->total(),
            ],
        ]);
    }

    /**
     * Update an album.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $album = PhotoAlbum::find($id);

        if (!$album) {
            return response()->json([
                'success' => false,
                'message' => 'Album not found',
            ], 404);
        }

        // Don't allow editing system albums
        if ($album->is_system_album) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot edit system albums',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'string|max:100',
            'description' => 'nullable|string|max:500',
            'privacy' => 'in:public,friends,private',
            'cover_photo_id' => 'nullable|exists:photos,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Verify cover photo belongs to this album
        if ($request->cover_photo_id) {
            $photo = Photo::find($request->cover_photo_id);
            if (!$photo || $photo->album_id !== $id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cover photo must belong to this album',
                ], 400);
            }
        }

        $album->update($request->only(['name', 'description', 'privacy', 'cover_photo_id']));

        return response()->json([
            'success' => true,
            'message' => 'Album updated',
            'data' => $album->fresh(['coverPhoto:id,thumbnail_path,file_path']),
        ]);
    }

    /**
     * Delete an album.
     */
    public function destroy(int $id): JsonResponse
    {
        $album = PhotoAlbum::find($id);

        if (!$album) {
            return response()->json([
                'success' => false,
                'message' => 'Album not found',
            ], 404);
        }

        // Don't allow deleting system albums
        if ($album->is_system_album) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete system albums',
            ], 403);
        }

        try {
            DB::beginTransaction();

            // Move photos to unassigned (null album)
            Photo::where('album_id', $id)->update(['album_id' => null]);

            // Delete album
            $album->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Album deleted. Photos have been moved to unassigned.',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete album: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get albums for a specific user.
     */
    public function getUserAlbums(int $userId): JsonResponse
    {
        $albums = PhotoAlbum::where('user_id', $userId)
            ->with('coverPhoto:id,thumbnail_path,file_path')
            ->withCount('photos')
            ->orderBy('is_system_album', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $albums,
        ]);
    }
}
