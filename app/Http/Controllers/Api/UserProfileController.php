<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserProfile;
use App\Models\Post;
use App\Models\Photo;
use App\Models\Friendship;
use App\Models\Subscription;
use App\Models\UserFollow;
use App\Models\SearchHistory;
use App\Jobs\AssignUserToDefaultGroups;
use App\Services\Firebase\FirebaseLiveUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class UserProfileController extends Controller
{
    protected FirebaseLiveUpdateService $firebaseLiveUpdate;

    public function __construct(FirebaseLiveUpdateService $firebaseLiveUpdate)
    {
        $this->firebaseLiveUpdate = $firebaseLiveUpdate;
    }
    /**
     * Register a new user profile
     */
    public function register(Request $request)
    {
        \Log::info('Registration request received', ['data' => $request->all()]);

        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone_number' => 'required|string|unique:user_profiles,phone_number',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|in:male,female',
        ]);

        if ($validator->fails()) {
            \Log::warning('Registration validation failed', ['errors' => $validator->errors()]);
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
        // Map request data to database fields
        $data = [
            // Bio
            'first_name' => $request->input('first_name'),
            'last_name' => $request->input('last_name'),
            'date_of_birth' => $request->input('date_of_birth'),
            'gender' => $request->input('gender'),
            'phone_number' => $request->input('phone_number'),
            'is_phone_verified' => $request->input('is_phone_verified', false),

            // Location
            'region_id' => $request->input('location.region_id'),
            'region_name' => $request->input('location.region_name'),
            'district_id' => $request->input('location.district_id'),
            'district_name' => $request->input('location.district_name'),
            'ward_id' => $request->input('location.ward_id'),
            'ward_name' => $request->input('location.ward_name'),
            'street_id' => $request->input('location.street_id'),
            'street_name' => $request->input('location.street_name'),

            // Primary School
            'primary_school_id' => $request->input('primary_school.school_id'),
            'primary_school_code' => $request->input('primary_school.school_code'),
            'primary_school_name' => $request->input('primary_school.school_name'),
            'primary_school_type' => $request->input('primary_school.school_type'),
            'primary_start_year' => $request->input('primary_school.start_year'),
            'primary_graduation_year' => $request->input('primary_school.graduation_year'),

            // Secondary School
            'secondary_school_id' => $request->input('secondary_school.school_id'),
            'secondary_school_code' => $request->input('secondary_school.school_code'),
            'secondary_school_name' => $request->input('secondary_school.school_name'),
            'secondary_school_type' => $request->input('secondary_school.school_type'),
            'secondary_start_year' => $request->input('secondary_school.start_year'),
            'secondary_graduation_year' => $request->input('secondary_school.graduation_year'),

            // A-Level
            'alevel_school_id' => $request->input('alevel_education.school_id'),
            'alevel_school_code' => $request->input('alevel_education.school_code'),
            'alevel_school_name' => $request->input('alevel_education.school_name'),
            'alevel_school_type' => $request->input('alevel_education.school_type'),
            'alevel_start_year' => $request->input('alevel_education.start_year'),
            'alevel_graduation_year' => $request->input('alevel_education.graduation_year'),
            'alevel_combination_code' => $request->input('alevel_education.combination_code'),
            'alevel_combination_name' => $request->input('alevel_education.combination_name'),
            'alevel_subjects' => $request->input('alevel_education.subjects'),

            // Post-Secondary
            'postsecondary_id' => $request->input('postsecondary_education.school_id'),
            'postsecondary_code' => $request->input('postsecondary_education.school_code'),
            'postsecondary_name' => $request->input('postsecondary_education.school_name'),
            'postsecondary_type' => $request->input('postsecondary_education.school_type'),
            'postsecondary_start_year' => $request->input('postsecondary_education.start_year'),
            'postsecondary_graduation_year' => $request->input('postsecondary_education.graduation_year'),

            // University
            'university_id' => $request->input('university_education.university_id'),
            'university_code' => $request->input('university_education.university_code'),
            'university_name' => $request->input('university_education.university_name'),
            'programme_id' => $request->input('university_education.programme_id'),
            'programme_name' => $request->input('university_education.programme_name'),
            'degree_level' => $request->input('university_education.degree_level'),
            'university_start_year' => $request->input('university_education.start_year'),
            'university_graduation_year' => $request->input('university_education.graduation_year'),
            'is_current_student' => $request->input('university_education.is_current_student', false),

            // Employer
            'employer_id' => $request->input('current_employer.employer_id'),
            'employer_code' => $request->input('current_employer.employer_code'),
            'employer_name' => $request->input('current_employer.employer_name'),
            'employer_sector' => $request->input('current_employer.sector'),
            'employer_ownership' => $request->input('current_employer.ownership'),
            'is_custom_employer' => $request->input('current_employer.is_custom_employer', false),
        ];

        $profile = UserProfile::create($data);

        // Auto-assign to system groups based on profile (school, location, etc.)
        AssignUserToDefaultGroups::dispatch($profile->id);

        \Log::info('Registration successful', ['user_id' => $profile->id, 'phone' => $profile->phone_number]);

        return response()->json([
            'success' => true,
            'message' => 'Registration successful',
            'data' => [
                'id' => $profile->id,
                'phone_number' => $profile->phone_number,
                'full_name' => $profile->full_name,
            ],
        ], 201);
        } catch (\Exception $e) {
            \Log::error('Registration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Registration failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get user profile by phone number
     */
    public function getByPhone(string $phoneNumber)
    {
        $profile = UserProfile::where('phone_number', $phoneNumber)->first();

        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $profile,
        ]);
    }

    /**
     * Update user profile
     */
    public function update(Request $request, string $phoneNumber)
    {
        $profile = UserProfile::where('phone_number', $phoneNumber)->first();

        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        // Snapshot profile before update (for auto-group diff)
        $oldProfile = $profile->only([
            'primary_school_id', 'primary_school_name', 'primary_start_year', 'primary_graduation_year',
            'secondary_school_id', 'secondary_school_name', 'secondary_start_year', 'secondary_graduation_year',
            'alevel_school_id', 'alevel_school_name', 'alevel_start_year', 'alevel_graduation_year',
            'alevel_combination_code', 'alevel_combination_name',
            'postsecondary_id', 'postsecondary_name', 'postsecondary_start_year', 'postsecondary_graduation_year',
            'university_id', 'university_name', 'programme_id', 'programme_name',
            'university_start_year', 'university_graduation_year', 'is_current_student',
            'region_id', 'region_name', 'district_id', 'district_name',
            'ward_id', 'ward_name', 'street_id', 'street_name',
            'employer_id', 'employer_name',
        ]);

        $profile->update($request->all());

        // Re-assign system groups if any group-relevant fields changed
        $groupFields = [
            'primary_school_id', 'primary_start_year', 'primary_graduation_year',
            'secondary_school_id', 'secondary_start_year', 'secondary_graduation_year',
            'alevel_school_id', 'alevel_start_year', 'alevel_graduation_year', 'alevel_combination_code',
            'postsecondary_id', 'postsecondary_start_year', 'postsecondary_graduation_year',
            'university_id', 'programme_id', 'university_start_year', 'university_graduation_year', 'is_current_student',
            'region_id', 'district_id', 'ward_id', 'street_id',
            'employer_id', 'employer_name',
        ];

        $changed = collect($groupFields)->contains(fn($field) => $request->has($field));
        if ($changed) {
            AssignUserToDefaultGroups::dispatch($profile->id, $oldProfile);
        }

        // Firebase: notify user that profile updated
        $this->firebaseLiveUpdate->notifyUser((int) $profile->id, 'profile_updated');

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => $profile,
        ]);
    }

    /**
     * Check if phone number is registered
     */
    public function checkPhone(Request $request)
    {
        $phoneNumber = $request->input('phone_number');

        $exists = UserProfile::where('phone_number', $phoneNumber)->exists();

        return response()->json([
            'success' => true,
            'available' => !$exists,
        ]);
    }

    /**
     * Get user profile by ID with full details
     */
    public function show(int $id, Request $request)
    {
        $profile = UserProfile::find($id);

        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        $currentUserId = $request->input('current_user_id');

        // Get friendship status if viewing another user's profile
        $friendshipStatus = null;
        $isFriend = false;
        if ($currentUserId && $currentUserId != $id) {
            $friendship = Friendship::getBetween($currentUserId, $id);
            if ($friendship) {
                $friendshipStatus = $friendship->status;
                $isFriend = $friendship->status === Friendship::STATUS_ACCEPTED;
                // Determine if current user initiated the request
                if ($friendship->status === Friendship::STATUS_PENDING) {
                    $friendshipStatus = $friendship->user_id == $currentUserId
                        ? 'request_sent'
                        : 'request_received';
                }
            }
        }

        // Get recent posts (last 5)
        $recentPosts = Post::where('user_id', $id)
            ->with(['media'])
            ->withCount(['likes', 'comments'])
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get()
            ->map(function ($post) use ($currentUserId) {
                $post->is_liked = $currentUserId
                    ? $post->likes()->where('user_id', $currentUserId)->exists()
                    : false;
                return $post;
            });

        // Get recent photos (last 6)
        $recentPhotos = Photo::where('user_id', $id)
            ->orderBy('created_at', 'desc')
            ->take(6)
            ->get();

        // Get mutual friends count if viewing another user
        $mutualFriendsCount = 0;
        if ($currentUserId && $currentUserId != $id) {
            $currentUserProfile = UserProfile::find($currentUserId);
            if ($currentUserProfile) {
                $currentUserFriends = $currentUserProfile->getFriendIds();
                $profileFriends = $profile->getFriendIds();
                $mutualFriendsCount = count(array_intersect($currentUserFriends, $profileFriends));
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                // Basic Info
                'id' => $profile->id,
                'first_name' => $profile->first_name,
                'last_name' => $profile->last_name,
                'full_name' => $profile->full_name,
                'username' => $profile->username,
                'phone_number' => $profile->phone_number,
                'date_of_birth' => $profile->date_of_birth?->format('Y-m-d'),
                'gender' => $profile->gender,
                'bio' => $profile->bio,
                'interests' => $profile->interests ?? [],
                'relationship_status' => $profile->relationship_status,

                // Photos
                'profile_photo_url' => $profile->profile_photo_url,
                'cover_photo_url' => $profile->cover_photo_url,

                // Stats
                'posts_count' => $profile->posts_count ?? Post::where('user_id', $id)->count(),
                'friends_count' => $profile->friends_count ?? 0,
                'followers_count' => $profile->followers_count ?? 0,
                'following_count' => $profile->following_count ?? 0,
                'subscribers_count' => Subscription::where('creator_id', $id)
                    ->where('status', Subscription::STATUS_ACTIVE)
                    ->where('expires_at', '>', now())
                    ->count(),
                'photos_count' => $profile->photos_count ?? Photo::where('user_id', $id)->count(),

                // Location
                'location' => [
                    'region_id' => $profile->region_id,
                    'region_name' => $profile->region_name,
                    'district_id' => $profile->district_id,
                    'district_name' => $profile->district_name,
                    'ward_id' => $profile->ward_id,
                    'ward_name' => $profile->ward_name,
                    'street_id' => $profile->street_id,
                    'street_name' => $profile->street_name,
                ],

                // Education
                'education' => [
                    'primary_school' => $profile->primary_school_id ? [
                        'school_id' => $profile->primary_school_id,
                        'school_name' => $profile->primary_school_name,
                        'school_code' => $profile->primary_school_code,
                        'graduation_year' => $profile->primary_graduation_year,
                    ] : null,
                    'secondary_school' => $profile->secondary_school_id ? [
                        'school_id' => $profile->secondary_school_id,
                        'school_name' => $profile->secondary_school_name,
                        'school_code' => $profile->secondary_school_code,
                        'graduation_year' => $profile->secondary_graduation_year,
                    ] : null,
                    'alevel' => $profile->alevel_school_id ? [
                        'school_id' => $profile->alevel_school_id,
                        'school_name' => $profile->alevel_school_name,
                        'school_code' => $profile->alevel_school_code,
                        'combination_code' => $profile->alevel_combination_code,
                        'combination_name' => $profile->alevel_combination_name,
                        'subjects' => $profile->alevel_subjects,
                        'graduation_year' => $profile->alevel_graduation_year,
                    ] : null,
                    'postsecondary' => $profile->postsecondary_id ? [
                        'institution_id' => $profile->postsecondary_id,
                        'institution_name' => $profile->postsecondary_name,
                        'institution_code' => $profile->postsecondary_code,
                        'graduation_year' => $profile->postsecondary_graduation_year,
                    ] : null,
                    'university' => $profile->university_id ? [
                        'university_id' => $profile->university_id,
                        'university_name' => $profile->university_name,
                        'university_code' => $profile->university_code,
                        'programme_id' => $profile->programme_id,
                        'programme_name' => $profile->programme_name,
                        'degree_level' => $profile->degree_level,
                        'graduation_year' => $profile->university_graduation_year,
                        'is_current_student' => $profile->is_current_student,
                    ] : null,
                ],

                // Employment
                'employer' => $profile->employer_id || $profile->employer_name ? [
                    'employer_id' => $profile->employer_id,
                    'employer_name' => $profile->employer_name,
                    'employer_code' => $profile->employer_code,
                    'sector' => $profile->employer_sector,
                    'ownership' => $profile->employer_ownership,
                    'is_custom' => $profile->is_custom_employer,
                ] : null,

                // Social
                'friendship_status' => $friendshipStatus,
                'is_friend' => $isFriend,
                'is_following' => $currentUserId && $currentUserId != $id
                    ? $this->safeIsFollowing((int) $currentUserId, $id)
                    : false,
                'is_followed_by' => $currentUserId && $currentUserId != $id
                    ? $this->safeIsFollowing($id, (int) $currentUserId)
                    : false,
                'mutual_friends_count' => $mutualFriendsCount,

                // Recent content
                'recent_posts' => $recentPosts,
                'recent_photos' => $recentPhotos,

                // Timestamps
                'last_active_at' => $profile->last_active_at?->toIso8601String(),
                'created_at' => $profile->created_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Update profile photo
     */
    public function updateProfilePhoto(Request $request, int $id)
    {
        $profile = UserProfile::find($id);

        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'photo' => 'required|image|mimes:jpeg,png,jpg,gif|max:5120', // 5MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Delete old photo if exists
        if ($profile->profile_photo_path) {
            Storage::disk('public')->delete($profile->profile_photo_path);
        }

        // Store new photo
        $path = $request->file('photo')->store('profile-photos', 'public');
        $profile->update(['profile_photo_path' => $path]);

        // Firebase: notify user and friends that profile updated
        $this->firebaseLiveUpdate->notifyUserAndFriends((int) $id, 'profile_updated');

        return response()->json([
            'success' => true,
            'message' => 'Profile photo updated',
            'data' => [
                'profile_photo_url' => $profile->profile_photo_url,
            ],
        ]);
    }

    /**
     * Update cover photo
     */
    public function updateCoverPhoto(Request $request, int $id)
    {
        $profile = UserProfile::find($id);

        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'photo' => 'required|image|mimes:jpeg,png,jpg,gif|max:10240', // 10MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Delete old photo if exists
        if ($profile->cover_photo_path) {
            Storage::disk('public')->delete($profile->cover_photo_path);
        }

        // Store new photo
        $path = $request->file('photo')->store('cover-photos', 'public');
        $profile->update(['cover_photo_path' => $path]);

        // Firebase: notify user that profile updated
        $this->firebaseLiveUpdate->notifyUser((int) $id, 'profile_updated');

        return response()->json([
            'success' => true,
            'message' => 'Cover photo updated',
            'data' => [
                'cover_photo_url' => $profile->cover_photo_url,
            ],
        ]);
    }

    /**
     * Update bio and interests
     */
    public function updateBio(Request $request, int $id)
    {
        $profile = UserProfile::find($id);

        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'bio' => 'nullable|string|max:500',
            'interests' => 'nullable|array',
            'interests.*' => 'string|max:100',
            'relationship_status' => 'nullable|string|in:single,in_relationship,engaged,married,complicated,divorced,widowed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $profile->update($request->only(['bio', 'interests', 'relationship_status']));

        // Firebase: notify user that profile updated
        $this->firebaseLiveUpdate->notifyUser((int) $id, 'profile_updated');

        return response()->json([
            'success' => true,
            'message' => 'Profile updated',
            'data' => [
                'bio' => $profile->bio,
                'interests' => $profile->interests,
                'relationship_status' => $profile->relationship_status,
            ],
        ]);
    }

    /**
     * Update username
     */
    public function updateUsername(Request $request, int $id)
    {
        $profile = UserProfile::find($id);

        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'username' => 'required|string|min:3|max:30|regex:/^[a-zA-Z0-9_]+$/|unique:user_profiles,username,' . $id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $profile->update(['username' => strtolower($request->username)]);

        // Firebase: notify user that profile updated
        $this->firebaseLiveUpdate->notifyUser((int) $id, 'profile_updated');

        return response()->json([
            'success' => true,
            'message' => 'Username updated',
            'data' => [
                'username' => $profile->username,
            ],
        ]);
    }

    /**
     * Get user privacy settings
     */
    public function getPrivacySettings(int $userId): JsonResponse
    {
        $profile = UserProfile::find($userId);
        if (!$profile) {
            return response()->json(['success' => false, 'message' => 'User not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'profile_visibility' => $profile->profile_visibility ?? 'everyone',
                'who_can_message' => $profile->who_can_message ?? 'everyone',
                'who_can_see_posts' => $profile->who_can_see_posts ?? 'everyone',
                'last_seen_visibility' => $profile->last_seen_visibility ?? 'everyone',
                'read_receipts_visibility' => $profile->read_receipts_visibility ?? 'everyone',
                'online_status_visibility' => $profile->online_status_visibility ?? 'everyone',
                'profile_photo_visibility' => $profile->profile_photo_visibility ?? 'everyone',
                'about_visibility' => $profile->about_visibility ?? 'everyone',
                'status_visibility' => $profile->status_visibility ?? 'everyone',
            ],
        ]);
    }

    /**
     * Update user privacy settings
     */
    public function updatePrivacySettings(Request $request, int $userId): JsonResponse
    {
        $profile = UserProfile::find($userId);
        if (!$profile) {
            return response()->json(['success' => false, 'message' => 'User not found'], 404);
        }

        $visibilityRule = 'sometimes|in:everyone,friends,nobody,only_me';

        $validator = Validator::make($request->all(), [
            'profile_visibility' => $visibilityRule,
            'who_can_message' => 'sometimes|in:everyone,friends,nobody',
            'who_can_see_posts' => $visibilityRule,
            'last_seen_visibility' => 'sometimes|in:everyone,friends,nobody',
            'read_receipts_visibility' => $visibilityRule,
            'online_status_visibility' => $visibilityRule,
            'profile_photo_visibility' => $visibilityRule,
            'about_visibility' => $visibilityRule,
            'status_visibility' => $visibilityRule,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $fields = [
            'profile_visibility', 'who_can_message', 'who_can_see_posts',
            'last_seen_visibility', 'read_receipts_visibility', 'online_status_visibility',
            'profile_photo_visibility', 'about_visibility', 'status_visibility',
        ];

        $profile->update($request->only($fields));

        $data = [];
        foreach ($fields as $field) {
            $data[$field] = $profile->$field;
        }

        return response()->json([
            'success' => true,
            'message' => 'Privacy settings updated',
            'data' => $data,
        ]);
    }

    /**
     * Get recent searches for a user
     */
    public function getRecentSearches(int $userId, Request $request): JsonResponse
    {
        $type = $request->query('type', 'general');

        $searches = SearchHistory::where('user_id', $userId)
            ->where('search_type', $type)
            ->orderByDesc('created_at')
            ->limit(20)
            ->pluck('query');

        return response()->json(['success' => true, 'data' => $searches]);
    }

    /**
     * Save a recent search
     */
    public function saveRecentSearch(int $userId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'query' => 'required|string|max:200',
            'search_type' => 'sometimes|in:clips,users,posts,general',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $searchType = $request->input('search_type', 'general');
        $query = $request->input('query');

        // Remove existing duplicate
        SearchHistory::where('user_id', $userId)
            ->where('query', $query)
            ->where('search_type', $searchType)
            ->delete();

        SearchHistory::create([
            'user_id' => $userId,
            'query' => $query,
            'search_type' => $searchType,
            'created_at' => now(),
        ]);

        return response()->json(['success' => true], 201);
    }

    /**
     * Clear recent searches
     */
    public function clearRecentSearches(int $userId, Request $request): JsonResponse
    {
        $type = $request->query('type', 'general');

        SearchHistory::where('user_id', $userId)
            ->where('search_type', $type)
            ->delete();

        return response()->json(['success' => true, 'message' => 'Search history cleared']);
    }

    /**
     * Safely check follow status — returns false if user_follows table is missing.
     */
    private function safeIsFollowing(int $followerId, int $followingId): bool
    {
        try {
            return UserFollow::isFollowing($followerId, $followingId);
        } catch (\Illuminate\Database\QueryException $e) {
            return false;
        }
    }
}
