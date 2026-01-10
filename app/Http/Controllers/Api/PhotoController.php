<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Photo;
use App\Models\PhotoAlbum;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Intervention\Image\Laravel\Facades\Image;

class PhotoController extends Controller
{
    /**
     * Get user's photos.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User ID is required',
            ], 400);
        }

        $photos = Photo::where('user_id', $userId)
            ->with('album:id,name')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'data' => $photos->items(),
            'meta' => [
                'current_page' => $photos->currentPage(),
                'last_page' => $photos->lastPage(),
                'per_page' => $photos->perPage(),
                'total' => $photos->total(),
            ],
        ]);
    }

    /**
     * Upload a photo.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'photo' => 'required|image|mimes:jpg,jpeg,png,gif|max:10240',
            'album_id' => 'nullable|exists:photo_albums,id',
            'caption' => 'nullable|string|max:500',
            'location_name' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $file = $request->file('photo');
            $userId = $request->user_id;

            // Store original photo
            $path = $file->store("photos/{$userId}", 'public');

            // Get image dimensions
            $imageInfo = getimagesize($file->getPathname());
            $width = $imageInfo[0] ?? null;
            $height = $imageInfo[1] ?? null;

            // Create thumbnail
            $thumbnailPath = null;
            try {
                $thumbnail = Image::read($file->getPathname());
                $thumbnail->cover(300, 300);
                $thumbnailPath = "photos/{$userId}/thumbnails/" . basename($path);
                Storage::disk('public')->put($thumbnailPath, $thumbnail->toJpeg(80));
            } catch (\Exception $e) {
                // Continue without thumbnail if image processing fails
            }

            // Create photo record
            $photo = Photo::create([
                'user_id' => $userId,
                'album_id' => $request->album_id,
                'file_path' => $path,
                'thumbnail_path' => $thumbnailPath,
                'width' => $width,
                'height' => $height,
                'file_size' => $file->getSize(),
                'caption' => $request->caption,
                'location_name' => $request->location_name,
            ]);

            // Update album photos count if album specified
            if ($request->album_id) {
                PhotoAlbum::find($request->album_id)->incrementPhotos();
            }

            // Update user photos count
            UserProfile::find($userId)->increment('photos_count');

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Photo uploaded successfully',
                'data' => $photo,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload photo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a single photo.
     */
    public function show(int $id): JsonResponse
    {
        $photo = Photo::with(['user:id,first_name,last_name,username,profile_photo_path', 'album:id,name'])
            ->find($id);

        if (!$photo) {
            return response()->json([
                'success' => false,
                'message' => 'Photo not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $photo,
        ]);
    }

    /**
     * Update photo details.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $photo = Photo::find($id);

        if (!$photo) {
            return response()->json([
                'success' => false,
                'message' => 'Photo not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'caption' => 'nullable|string|max:500',
            'location_name' => 'nullable|string|max:255',
            'album_id' => 'nullable|exists:photo_albums,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Handle album change
        if ($request->has('album_id') && $photo->album_id !== $request->album_id) {
            // Decrement old album count
            if ($photo->album_id) {
                PhotoAlbum::find($photo->album_id)->decrementPhotos();
            }
            // Increment new album count
            if ($request->album_id) {
                PhotoAlbum::find($request->album_id)->incrementPhotos();
            }
        }

        $photo->update($request->only(['caption', 'location_name', 'album_id']));

        return response()->json([
            'success' => true,
            'message' => 'Photo updated',
            'data' => $photo->fresh(['album:id,name']),
        ]);
    }

    /**
     * Delete a photo.
     */
    public function destroy(int $id): JsonResponse
    {
        $photo = Photo::find($id);

        if (!$photo) {
            return response()->json([
                'success' => false,
                'message' => 'Photo not found',
            ], 404);
        }

        try {
            DB::beginTransaction();

            // Delete files
            Storage::disk('public')->delete($photo->file_path);
            if ($photo->thumbnail_path) {
                Storage::disk('public')->delete($photo->thumbnail_path);
            }

            // Update counts
            if ($photo->album_id) {
                PhotoAlbum::find($photo->album_id)->decrementPhotos();
            }
            UserProfile::find($photo->user_id)->decrement('photos_count');

            // Delete record
            $photo->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Photo deleted',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete photo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get photos for a specific user.
     */
    public function getUserPhotos(Request $request, int $userId): JsonResponse
    {
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);

        $photos = Photo::where('user_id', $userId)
            ->with('album:id,name')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'data' => $photos->items(),
            'meta' => [
                'current_page' => $photos->currentPage(),
                'last_page' => $photos->lastPage(),
                'per_page' => $photos->perPage(),
                'total' => $photos->total(),
            ],
        ]);
    }
}
