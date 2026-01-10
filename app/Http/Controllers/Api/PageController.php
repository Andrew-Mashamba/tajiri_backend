<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PageRole;
use App\Models\PageFollower;
use App\Models\PagePost;
use App\Models\PageReview;
use App\Models\Post;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PageController extends Controller
{
    /**
     * Get list of pages.
     */
    public function index(Request $request): JsonResponse
    {
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);
        $category = $request->query('category');
        $search = $request->query('search');
        $currentUserId = $request->query('current_user_id');

        $query = Page::with(['creator:id,first_name,last_name,username,profile_photo_path']);

        if ($category) {
            $query->where('category', $category);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        $pages = $query->orderBy('followers_count', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        if ($currentUserId) {
            foreach ($pages as $pg) {
                $pg->is_following = $pg->isFollowedBy($currentUserId);
                $pg->is_liked = $pg->isLikedBy($currentUserId);
                $pg->user_role = $pg->getUserRole($currentUserId);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $pages->items(),
            'meta' => [
                'current_page' => $pages->currentPage(),
                'last_page' => $pages->lastPage(),
                'per_page' => $pages->perPage(),
                'total' => $pages->total(),
            ],
        ]);
    }

    /**
     * Get page categories.
     */
    public function categories(): JsonResponse
    {
        $categories = [
            ['value' => 'business', 'label' => 'Biashara'],
            ['value' => 'brand', 'label' => 'Chapa/Brand'],
            ['value' => 'community', 'label' => 'Jamii'],
            ['value' => 'entertainment', 'label' => 'Burudani'],
            ['value' => 'education', 'label' => 'Elimu'],
            ['value' => 'government', 'label' => 'Serikali'],
            ['value' => 'nonprofit', 'label' => 'Mashirika yasiyo ya faida'],
            ['value' => 'health', 'label' => 'Afya'],
            ['value' => 'news', 'label' => 'Habari'],
            ['value' => 'sports', 'label' => 'Michezo'],
            ['value' => 'other', 'label' => 'Nyingine'],
        ];

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    /**
     * Get user's pages (managed by user).
     */
    public function userPages(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'User ID required'], 400);
        }

        $pages = Page::whereHas('roles', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })
        ->with(['creator:id,first_name,last_name,username,profile_photo_path'])
        ->get();

        foreach ($pages as $pg) {
            $pg->user_role = $pg->getUserRole($userId);
        }

        return response()->json([
            'success' => true,
            'data' => $pages,
        ]);
    }

    /**
     * Get pages liked by user.
     */
    public function likedPages(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'User ID required'], 400);
        }

        $pages = Page::whereHas('likedBy', function ($q) use ($userId) {
            $q->where('user_profiles.id', $userId);
        })->get();

        return response()->json([
            'success' => true,
            'data' => $pages,
        ]);
    }

    /**
     * Create a new page.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'creator_id' => 'required|exists:user_profiles,id',
            'name' => 'required|string|max:100',
            'category' => 'required|string|max:50',
            'subcategory' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:5000',
            'website' => 'nullable|url|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'hours' => 'nullable|array',
            'social_links' => 'nullable|array',
            'profile_photo' => 'nullable|image|max:5120',
            'cover_photo' => 'nullable|image|max:5120',
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

            $profilePath = null;
            $coverPath = null;

            if ($request->hasFile('profile_photo')) {
                $profilePath = $request->file('profile_photo')->store('pages/profiles', 'public');
            }
            if ($request->hasFile('cover_photo')) {
                $coverPath = $request->file('cover_photo')->store('pages/covers', 'public');
            }

            $page = Page::create([
                'name' => $request->name,
                'slug' => Str::slug($request->name) . '-' . Str::random(6),
                'category' => $request->category,
                'subcategory' => $request->subcategory,
                'description' => $request->description,
                'website' => $request->website,
                'phone' => $request->phone,
                'email' => $request->email,
                'address' => $request->address,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'hours' => $request->hours,
                'social_links' => $request->social_links,
                'creator_id' => $request->creator_id,
                'profile_photo_path' => $profilePath,
                'cover_photo_path' => $coverPath,
            ]);

            // Add creator as admin
            PageRole::create([
                'page_id' => $page->id,
                'user_id' => $request->creator_id,
                'role' => Page::ROLE_ADMIN,
            ]);

            DB::commit();

            $page->load('creator:id,first_name,last_name,username,profile_photo_path');

            return response()->json([
                'success' => true,
                'message' => 'Page created successfully',
                'data' => $page,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create page: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a single page.
     */
    public function show(string $identifier, Request $request): JsonResponse
    {
        $page = Page::where('id', $identifier)
            ->orWhere('slug', $identifier)
            ->with(['creator:id,first_name,last_name,username,profile_photo_path'])
            ->withCount(['followers', 'reviews'])
            ->first();

        if (!$page) {
            return response()->json([
                'success' => false,
                'message' => 'Page not found',
            ], 404);
        }

        $page->average_rating = $page->reviews()->avg('rating');

        $currentUserId = $request->query('current_user_id');
        if ($currentUserId) {
            $page->is_following = $page->isFollowedBy($currentUserId);
            $page->is_liked = $page->isLikedBy($currentUserId);
            $page->user_role = $page->getUserRole($currentUserId);
            $page->can_manage = $page->canManage($currentUserId);
        }

        return response()->json([
            'success' => true,
            'data' => $page,
        ]);
    }

    /**
     * Update a page.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $page = Page::find($id);
        if (!$page) {
            return response()->json(['success' => false, 'message' => 'Page not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'string|max:100',
            'category' => 'string|max:50',
            'subcategory' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:5000',
            'website' => 'nullable|url|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'hours' => 'nullable|array',
            'social_links' => 'nullable|array',
            'profile_photo' => 'nullable|image|max:5120',
            'cover_photo' => 'nullable|image|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->hasFile('profile_photo')) {
            if ($page->profile_photo_path) {
                Storage::disk('public')->delete($page->profile_photo_path);
            }
            $page->profile_photo_path = $request->file('profile_photo')->store('pages/profiles', 'public');
        }

        if ($request->hasFile('cover_photo')) {
            if ($page->cover_photo_path) {
                Storage::disk('public')->delete($page->cover_photo_path);
            }
            $page->cover_photo_path = $request->file('cover_photo')->store('pages/covers', 'public');
        }

        $page->update($request->only([
            'name', 'category', 'subcategory', 'description', 'website',
            'phone', 'email', 'address', 'latitude', 'longitude', 'hours', 'social_links'
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Page updated successfully',
            'data' => $page->fresh(['creator:id,first_name,last_name,username,profile_photo_path']),
        ]);
    }

    /**
     * Delete a page.
     */
    public function destroy(int $id): JsonResponse
    {
        $page = Page::find($id);
        if (!$page) {
            return response()->json(['success' => false, 'message' => 'Page not found'], 404);
        }

        if ($page->profile_photo_path) {
            Storage::disk('public')->delete($page->profile_photo_path);
        }
        if ($page->cover_photo_path) {
            Storage::disk('public')->delete($page->cover_photo_path);
        }

        $page->delete();

        return response()->json([
            'success' => true,
            'message' => 'Page deleted successfully',
        ]);
    }

    /**
     * Follow a page.
     */
    public function follow(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $page = Page::find($id);
        if (!$page) {
            return response()->json(['success' => false, 'message' => 'Page not found'], 404);
        }

        if ($page->isFollowedBy($request->user_id)) {
            return response()->json(['success' => false, 'message' => 'Already following'], 400);
        }

        PageFollower::create([
            'page_id' => $id,
            'user_id' => $request->user_id,
        ]);

        $page->incrementFollowers();

        return response()->json([
            'success' => true,
            'message' => 'Now following page',
            'data' => ['followers_count' => $page->fresh()->followers_count],
        ]);
    }

    /**
     * Unfollow a page.
     */
    public function unfollow(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $page = Page::find($id);
        if (!$page) {
            return response()->json(['success' => false, 'message' => 'Page not found'], 404);
        }

        $follower = PageFollower::where('page_id', $id)
            ->where('user_id', $request->user_id)
            ->first();

        if (!$follower) {
            return response()->json(['success' => false, 'message' => 'Not following'], 400);
        }

        $follower->delete();
        $page->decrementFollowers();

        return response()->json([
            'success' => true,
            'message' => 'Unfollowed page',
            'data' => ['followers_count' => $page->fresh()->followers_count],
        ]);
    }

    /**
     * Like a page.
     */
    public function like(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $page = Page::find($id);
        if (!$page) {
            return response()->json(['success' => false, 'message' => 'Page not found'], 404);
        }

        if ($page->isLikedBy($request->user_id)) {
            return response()->json(['success' => false, 'message' => 'Already liked'], 400);
        }

        $page->likedBy()->attach($request->user_id);
        $page->incrementLikes();

        // Also auto-follow if not following
        if (!$page->isFollowedBy($request->user_id)) {
            PageFollower::create([
                'page_id' => $id,
                'user_id' => $request->user_id,
            ]);
            $page->incrementFollowers();
        }

        return response()->json([
            'success' => true,
            'message' => 'Page liked',
            'data' => ['likes_count' => $page->fresh()->likes_count],
        ]);
    }

    /**
     * Unlike a page.
     */
    public function unlike(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $page = Page::find($id);
        if (!$page) {
            return response()->json(['success' => false, 'message' => 'Page not found'], 404);
        }

        if (!$page->isLikedBy($request->user_id)) {
            return response()->json(['success' => false, 'message' => 'Not liked'], 400);
        }

        $page->likedBy()->detach($request->user_id);
        $page->decrementLikes();

        return response()->json([
            'success' => true,
            'message' => 'Page unliked',
            'data' => ['likes_count' => $page->fresh()->likes_count],
        ]);
    }

    /**
     * Get page posts.
     */
    public function posts(Request $request, int $id): JsonResponse
    {
        $page = Page::find($id);
        if (!$page) {
            return response()->json(['success' => false, 'message' => 'Page not found'], 404);
        }

        $pageNum = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);

        $posts = $page->posts()
            ->with(['user:id,first_name,last_name,username,profile_photo_path', 'media'])
            ->withCount(['comments', 'likes'])
            ->paginate($perPage, ['*'], 'page', $pageNum);

        return response()->json([
            'success' => true,
            'data' => $posts->items(),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]);
    }

    /**
     * Create a post on a page.
     */
    public function createPost(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'content' => 'nullable|string|max:5000',
            'post_type' => 'in:text,photo,video',
            'media' => 'nullable|array|max:10',
            'media.*' => 'file|mimes:jpg,jpeg,png,gif,mp4,mov|max:51200',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $page = Page::find($id);
        if (!$page) {
            return response()->json(['success' => false, 'message' => 'Page not found'], 404);
        }

        // Check if user can post on this page
        if (!$page->canManage($request->user_id)) {
            return response()->json(['success' => false, 'message' => 'Not authorized to post'], 403);
        }

        try {
            DB::beginTransaction();

            $post = Post::create([
                'user_id' => $request->user_id,
                'content' => $request->content,
                'post_type' => $request->post_type ?? 'text',
                'privacy' => 'public',
            ]);

            // Handle media uploads
            if ($request->hasFile('media')) {
                $order = 0;
                foreach ($request->file('media') as $file) {
                    $path = $file->store('posts/' . $post->id, 'public');
                    $post->media()->create([
                        'media_type' => str_starts_with($file->getMimeType(), 'video/') ? 'video' : 'image',
                        'file_path' => $path,
                        'original_filename' => $file->getClientOriginalName(),
                        'file_size' => $file->getSize(),
                        'order' => $order++,
                    ]);
                }
                if ($post->post_type === 'text') {
                    $post->update(['post_type' => 'photo']);
                }
            }

            // Link post to page
            PagePost::create([
                'page_id' => $id,
                'post_id' => $post->id,
                'posted_by' => $request->user_id,
            ]);

            $page->incrementPosts();

            DB::commit();

            $post->load(['user:id,first_name,last_name,username,profile_photo_path', 'media']);

            return response()->json([
                'success' => true,
                'message' => 'Post created successfully',
                'data' => $post,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create post: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add a review to a page.
     */
    public function addReview(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'rating' => 'required|integer|between:1,5',
            'content' => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $page = Page::find($id);
        if (!$page) {
            return response()->json(['success' => false, 'message' => 'Page not found'], 404);
        }

        // Check if user already reviewed
        $existingReview = PageReview::where('page_id', $id)
            ->where('user_id', $request->user_id)
            ->first();

        if ($existingReview) {
            $existingReview->update([
                'rating' => $request->rating,
                'content' => $request->content,
            ]);
            $review = $existingReview;
            $message = 'Review updated';
        } else {
            $review = PageReview::create([
                'page_id' => $id,
                'user_id' => $request->user_id,
                'rating' => $request->rating,
                'content' => $request->content,
            ]);
            $message = 'Review added';
        }

        $review->load('user:id,first_name,last_name,username,profile_photo_path');

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $review,
        ]);
    }

    /**
     * Get page reviews.
     */
    public function reviews(Request $request, int $id): JsonResponse
    {
        $page = Page::find($id);
        if (!$page) {
            return response()->json(['success' => false, 'message' => 'Page not found'], 404);
        }

        $pageNum = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);

        $reviews = PageReview::where('page_id', $id)
            ->with('user:id,first_name,last_name,username,profile_photo_path')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $pageNum);

        return response()->json([
            'success' => true,
            'data' => $reviews->items(),
            'meta' => [
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
                'average_rating' => $page->reviews()->avg('rating'),
            ],
        ]);
    }

    /**
     * Manage page roles.
     */
    public function updateRole(Request $request, int $pageId, int $userId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'role' => 'required|in:admin,editor,moderator,analyst',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $page = Page::find($pageId);
        if (!$page) {
            return response()->json(['success' => false, 'message' => 'Page not found'], 404);
        }

        $role = PageRole::updateOrCreate(
            ['page_id' => $pageId, 'user_id' => $userId],
            ['role' => $request->role]
        );

        return response()->json([
            'success' => true,
            'message' => 'Role updated successfully',
            'data' => $role,
        ]);
    }

    /**
     * Remove page role.
     */
    public function removeRole(int $pageId, int $userId): JsonResponse
    {
        $page = Page::find($pageId);
        if (!$page) {
            return response()->json(['success' => false, 'message' => 'Page not found'], 404);
        }

        if ($page->creator_id === $userId) {
            return response()->json(['success' => false, 'message' => 'Cannot remove creator'], 400);
        }

        PageRole::where('page_id', $pageId)->where('user_id', $userId)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Role removed successfully',
        ]);
    }

    /**
     * Search pages.
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->query('q');
        if (!$query) {
            return response()->json(['success' => false, 'message' => 'Query required'], 400);
        }

        $pages = Page::where(function ($q) use ($query) {
            $q->where('name', 'LIKE', "%{$query}%")
              ->orWhere('description', 'LIKE', "%{$query}%");
        })
        ->with(['creator:id,first_name,last_name,username,profile_photo_path'])
        ->limit(20)
        ->get();

        return response()->json([
            'success' => true,
            'data' => $pages,
        ]);
    }
}
