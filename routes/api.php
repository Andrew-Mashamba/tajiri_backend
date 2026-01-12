<?php

use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\SchoolController;
use App\Http\Controllers\Api\SecondarySchoolController;
use App\Http\Controllers\Api\AlevelSchoolController;
use App\Http\Controllers\Api\PostsecondaryController;
use App\Http\Controllers\Api\UniversityController;
use App\Http\Controllers\Api\UniversityDetailedController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\UserProfileController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\PhotoController;
use App\Http\Controllers\Api\AlbumController;
use App\Http\Controllers\Api\FriendController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\FeedController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\PageController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\PollController;
use App\Http\Controllers\Api\StoryController;
use App\Http\Controllers\Api\ClipController;
use App\Http\Controllers\Api\MusicController;
use App\Http\Controllers\Api\LiveStreamController;
use App\Http\Controllers\Api\CallController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\PostDraftController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/*
|--------------------------------------------------------------------------
| Location API Routes
|--------------------------------------------------------------------------
*/
Route::prefix('locations')->group(function () {
    Route::get('/regions', [LocationController::class, 'regions']);
    Route::get('/regions/{region}/districts', [LocationController::class, 'districts']);
    Route::get('/districts/{district}/wards', [LocationController::class, 'wards']);
    Route::get('/wards/{ward}/streets', [LocationController::class, 'streets']);
    Route::get('/search', [LocationController::class, 'search']);
    Route::get('/hierarchy', [LocationController::class, 'hierarchy']);
    Route::get('/streets/{street}/full', [LocationController::class, 'getFullLocation']);
});

/*
|--------------------------------------------------------------------------
| School API Routes
|--------------------------------------------------------------------------
*/
Route::prefix('schools')->group(function () {
    Route::get('/stats', [SchoolController::class, 'stats']);
    Route::get('/regions', [SchoolController::class, 'regions']);
    Route::get('/regions/{regionCode}/districts', [SchoolController::class, 'districts']);
    Route::get('/districts/{districtCode}/schools', [SchoolController::class, 'schoolsInDistrict']);
    Route::get('/search', [SchoolController::class, 'search']);
    Route::get('/{identifier}', [SchoolController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| Secondary School API Routes (O-Level)
|--------------------------------------------------------------------------
*/
Route::prefix('secondary-schools')->group(function () {
    Route::get('/stats', [SecondarySchoolController::class, 'stats']);
    Route::get('/regions', [SecondarySchoolController::class, 'regions']);
    Route::get('/regions/{regionCode}/districts', [SecondarySchoolController::class, 'districts']);
    Route::get('/districts/{districtCode}/schools', [SecondarySchoolController::class, 'schoolsInDistrict']);
    Route::get('/search', [SecondarySchoolController::class, 'search']);
    Route::get('/{identifier}', [SecondarySchoolController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| A-Level School API Routes (ACSEE)
|--------------------------------------------------------------------------
*/
Route::prefix('alevel-schools')->group(function () {
    Route::get('/stats', [AlevelSchoolController::class, 'stats']);
    Route::get('/regions', [AlevelSchoolController::class, 'regions']);
    Route::get('/regions/{regionCode}/districts', [AlevelSchoolController::class, 'districts']);
    Route::get('/districts/{districtCode}/schools', [AlevelSchoolController::class, 'schoolsInDistrict']);
    Route::get('/combinations', [AlevelSchoolController::class, 'combinations']);
    Route::get('/search', [AlevelSchoolController::class, 'search']);
    Route::get('/{schoolId}/combinations', [AlevelSchoolController::class, 'schoolCombinations']);
    Route::get('/{identifier}', [AlevelSchoolController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| Post-Secondary Institutions API (VET, TTC, Health, etc.)
|--------------------------------------------------------------------------
*/
Route::prefix('postsecondary')->group(function () {
    Route::get('/stats', [PostsecondaryController::class, 'stats']);
    Route::get('/categories', [PostsecondaryController::class, 'categories']);
    Route::get('/category/{category}', [PostsecondaryController::class, 'byCategory']);
    Route::get('/search', [PostsecondaryController::class, 'search']);
    Route::get('/{identifier}', [PostsecondaryController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| Universities API (TCU Registered)
|--------------------------------------------------------------------------
*/
Route::prefix('universities')->group(function () {
    Route::get('/', [UniversityController::class, 'index']);
    Route::get('/stats', [UniversityController::class, 'stats']);
    Route::get('/categories', [UniversityController::class, 'categories']);
    Route::get('/category/{category}', [UniversityController::class, 'byCategory']);
    Route::get('/search', [UniversityController::class, 'search']);
    Route::get('/{identifier}', [UniversityController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| Universities Detailed API (With Hierarchy)
|--------------------------------------------------------------------------
*/
Route::prefix('universities-detailed')->group(function () {
    Route::get('/', [UniversityDetailedController::class, 'index']);
    Route::get('/stats', [UniversityDetailedController::class, 'stats']);
    Route::get('/types', [UniversityDetailedController::class, 'types']);
    Route::get('/degree-levels', [UniversityDetailedController::class, 'degreeLevels']);
    Route::get('/search', [UniversityDetailedController::class, 'search']);
    Route::get('/programmes/search', [UniversityDetailedController::class, 'searchProgrammes']);
    Route::get('/{id}', [UniversityDetailedController::class, 'show']);
    Route::get('/{id}/colleges', [UniversityDetailedController::class, 'colleges']);
    Route::get('/{id}/programmes', [UniversityDetailedController::class, 'programmesByUniversity']);
    Route::get('/colleges/{collegeId}/departments', [UniversityDetailedController::class, 'departments']);
    Route::get('/colleges/{collegeId}/programmes', [UniversityDetailedController::class, 'programmesByCollege']);
    Route::get('/departments/{departmentId}/programmes', [UniversityDetailedController::class, 'programmesByDepartment']);
});

/*
|--------------------------------------------------------------------------
| Business API Routes (DSE, Parastatals, Private Companies)
|--------------------------------------------------------------------------
*/
Route::prefix('businesses')->group(function () {
    Route::get('/', [BusinessController::class, 'index']);
    Route::get('/stats', [BusinessController::class, 'stats']);
    Route::get('/sectors', [BusinessController::class, 'sectors']);
    Route::get('/categories', [BusinessController::class, 'categories']);
    Route::get('/ownership-types', [BusinessController::class, 'ownershipTypes']);
    Route::get('/ownership/{ownership}', [BusinessController::class, 'byOwnership']);
    Route::get('/category/{category}', [BusinessController::class, 'byCategory']);
    Route::get('/sector/{sector}', [BusinessController::class, 'bySector']);
    Route::get('/conglomerates', [BusinessController::class, 'conglomerates']);
    Route::get('/parastatals', [BusinessController::class, 'parastatals']);
    Route::get('/dse', [BusinessController::class, 'dseCompanies']);
    Route::get('/search', [BusinessController::class, 'search']);
    Route::get('/{identifier}', [BusinessController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| User Profile API Routes
|--------------------------------------------------------------------------
*/
Route::prefix('users')->group(function () {
    Route::post('/register', [UserProfileController::class, 'register']);
    Route::post('/check-phone', [UserProfileController::class, 'checkPhone']);
    Route::get('/phone/{phoneNumber}', [UserProfileController::class, 'getByPhone']);
    Route::put('/phone/{phoneNumber}', [UserProfileController::class, 'update']);
    Route::get('/search', [FriendController::class, 'searchUsers']);

    // Full profile endpoints
    Route::get('/{id}', [UserProfileController::class, 'show']);
    Route::post('/{id}/profile-photo', [UserProfileController::class, 'updateProfilePhoto']);
    Route::post('/{id}/cover-photo', [UserProfileController::class, 'updateCoverPhoto']);
    Route::put('/{id}/bio', [UserProfileController::class, 'updateBio']);
    Route::put('/{id}/username', [UserProfileController::class, 'updateUsername']);

    // User content endpoints
    Route::get('/{id}/posts', [PostController::class, 'getUserWall']);
    Route::get('/{id}/photos', [PhotoController::class, 'getUserPhotos']);
    Route::get('/{id}/albums', [AlbumController::class, 'getUserAlbums']);
});

/*
|--------------------------------------------------------------------------
| Posts API Routes
|--------------------------------------------------------------------------
*/
Route::prefix('posts')->group(function () {
    Route::get('/', [PostController::class, 'index']);
    Route::post('/', [PostController::class, 'store']);

    // Feed algorithms (must be before /{id} to avoid conflicts)
    Route::get('/feed/for-you', [PostController::class, 'forYouFeed']);
    Route::get('/feed/following', [PostController::class, 'followingFeed']);
    Route::get('/feed/shorts', [PostController::class, 'shortsFeed']);
    Route::get('/feed/audio', [PostController::class, 'audioFeed']);
    Route::get('/feed/trending', [PostController::class, 'trendingFeed']);
    Route::get('/feed/discover', [PostController::class, 'discoverFeed']);

    // Saved posts
    Route::get('/saved', [PostController::class, 'getSavedPosts']);

    // Search routes (must be before /{id} to avoid conflicts)
    Route::get('/hashtag/{hashtag}', [PostController::class, 'searchByHashtag']);
    Route::get('/mention/{username}', [PostController::class, 'searchByMention']);

    Route::get('/{id}', [PostController::class, 'show']);
    Route::put('/{id}', [PostController::class, 'update']);
    Route::delete('/{id}', [PostController::class, 'destroy']);
    Route::post('/{id}/like', [PostController::class, 'like']);
    Route::delete('/{id}/like', [PostController::class, 'unlike']);
    Route::get('/{id}/likes', [PostController::class, 'getLikes']);
    Route::post('/{id}/share', [PostController::class, 'share']);

    // View tracking & engagement
    Route::post('/{id}/view', [PostController::class, 'recordView']);

    // Save/Bookmark
    Route::post('/{id}/save', [PostController::class, 'savePost']);
    Route::delete('/{id}/save', [PostController::class, 'unsavePost']);

    Route::get('/{id}/comments', [CommentController::class, 'index']);
    Route::post('/{id}/comments', [CommentController::class, 'store']);
});

/*
|--------------------------------------------------------------------------
| Post Drafts API Routes
|--------------------------------------------------------------------------
*/
Route::prefix('drafts')->group(function () {
    Route::get('/', [PostDraftController::class, 'index']);
    Route::get('/counts', [PostDraftController::class, 'counts']);
    Route::post('/', [PostDraftController::class, 'store']);
    Route::get('/{id}', [PostDraftController::class, 'show']);
    Route::delete('/{id}', [PostDraftController::class, 'destroy']);
    Route::post('/{id}/publish', [PostDraftController::class, 'publish']);
    Route::post('/{id}/duplicate', [PostDraftController::class, 'duplicate']);
    Route::delete('/', [PostDraftController::class, 'destroyAll']);
});

/*
|--------------------------------------------------------------------------
| Hashtags API Routes
|--------------------------------------------------------------------------
*/
Route::prefix('hashtags')->group(function () {
    Route::get('/trending', [PostController::class, 'trendingHashtags']);
    Route::get('/search', [PostController::class, 'searchHashtags']);
});

/*
|--------------------------------------------------------------------------
| Comments API Routes
|--------------------------------------------------------------------------
*/
Route::prefix('comments')->group(function () {
    Route::put('/{id}', [CommentController::class, 'update']);
    Route::delete('/{id}', [CommentController::class, 'destroy']);
    Route::get('/{id}/replies', [CommentController::class, 'getReplies']);
});

/*
|--------------------------------------------------------------------------
| Photos API Routes
|--------------------------------------------------------------------------
*/
Route::prefix('photos')->group(function () {
    Route::get('/', [PhotoController::class, 'index']);
    Route::post('/', [PhotoController::class, 'store']);
    Route::get('/{id}', [PhotoController::class, 'show']);
    Route::put('/{id}', [PhotoController::class, 'update']);
    Route::delete('/{id}', [PhotoController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Albums API Routes
|--------------------------------------------------------------------------
*/
Route::prefix('albums')->group(function () {
    Route::get('/', [AlbumController::class, 'index']);
    Route::post('/', [AlbumController::class, 'store']);
    Route::get('/{id}', [AlbumController::class, 'show']);
    Route::put('/{id}', [AlbumController::class, 'update']);
    Route::delete('/{id}', [AlbumController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Friends API Routes
|--------------------------------------------------------------------------
*/
Route::prefix('friends')->group(function () {
    Route::get('/', [FriendController::class, 'index']);
    Route::post('/request', [FriendController::class, 'sendRequest']);
    Route::post('/accept/{requesterId}', [FriendController::class, 'acceptRequest']);
    Route::post('/decline/{requesterId}', [FriendController::class, 'declineRequest']);
    Route::post('/cancel/{friendId}', [FriendController::class, 'cancelRequest']);
    Route::delete('/{friendId}', [FriendController::class, 'removeFriend']);
    Route::get('/requests', [FriendController::class, 'getRequests']);
    Route::get('/suggestions', [FriendController::class, 'getSuggestions']);
    Route::get('/mutual/{otherUserId}', [FriendController::class, 'getMutualFriends']);
    Route::get('/status/{otherUserId}', [FriendController::class, 'checkStatus']);
});

/*
|--------------------------------------------------------------------------
| Conversations & Messaging API Routes
|--------------------------------------------------------------------------
*/
Route::prefix('conversations')->group(function () {
    Route::get('/', [ConversationController::class, 'index']);
    Route::post('/', [ConversationController::class, 'store']);
    Route::get('/unread-count', [ConversationController::class, 'getUnreadCount']);
    Route::get('/private/{otherUserId}', [ConversationController::class, 'getPrivate']);
    Route::get('/{id}', [ConversationController::class, 'show']);
    Route::delete('/{id}', [ConversationController::class, 'destroy']);
    Route::get('/{id}/messages', [ConversationController::class, 'getMessages']);
    Route::post('/{id}/messages', [ConversationController::class, 'sendMessage']);
    Route::put('/{id}/read', [ConversationController::class, 'markAsRead']);

    // Typing indicators
    Route::post('/{id}/typing/start', [ConversationController::class, 'startTyping']);
    Route::post('/{id}/typing/stop', [ConversationController::class, 'stopTyping']);
    Route::get('/{id}/typing', [ConversationController::class, 'getTypingStatus']);
});

/*
|--------------------------------------------------------------------------
| Feed API Routes
|--------------------------------------------------------------------------
*/
Route::prefix('feed')->group(function () {
    Route::get('/', [FeedController::class, 'index']);
    Route::get('/friends', [FeedController::class, 'friendsFeed']);
    Route::get('/discover', [FeedController::class, 'discover']);
    Route::get('/trending', [FeedController::class, 'trending']);
    Route::get('/nearby', [FeedController::class, 'nearby']);
});

/*
|--------------------------------------------------------------------------
| Groups API Routes
|--------------------------------------------------------------------------
*/
Route::prefix('groups')->group(function () {
    Route::get('/', [GroupController::class, 'index']);
    Route::get('/user', [GroupController::class, 'userGroups']);
    Route::get('/invitations', [GroupController::class, 'userInvitations']);
    Route::get('/search', [GroupController::class, 'search']);
    Route::post('/', [GroupController::class, 'store']);
    Route::get('/{identifier}', [GroupController::class, 'show']);
    Route::put('/{id}', [GroupController::class, 'update']);
    Route::delete('/{id}', [GroupController::class, 'destroy']);

    // Membership
    Route::post('/{id}/join', [GroupController::class, 'join']);
    Route::post('/{id}/leave', [GroupController::class, 'leave']);
    Route::get('/{id}/members', [GroupController::class, 'members']);
    Route::post('/{groupId}/members/{userId}/handle', [GroupController::class, 'handleRequest']);
    Route::put('/{groupId}/members/{userId}/role', [GroupController::class, 'updateRole']);
    Route::delete('/{groupId}/members/{userId}', [GroupController::class, 'removeMember']);
    Route::post('/{groupId}/members/{userId}/ban', [GroupController::class, 'banMember']);

    // Posts
    Route::get('/{id}/posts', [GroupController::class, 'posts']);
    Route::post('/{id}/posts', [GroupController::class, 'createPost']);

    // Invitations
    Route::post('/{id}/invite', [GroupController::class, 'invite']);
    Route::post('/invitations/{invitationId}/respond', [GroupController::class, 'respondToInvitation']);
});

/*
|--------------------------------------------------------------------------
| Pages API Routes
|--------------------------------------------------------------------------
*/
Route::prefix('pages')->group(function () {
    Route::get('/', [PageController::class, 'index']);
    Route::get('/categories', [PageController::class, 'categories']);
    Route::get('/user', [PageController::class, 'userPages']);
    Route::get('/liked', [PageController::class, 'likedPages']);
    Route::get('/search', [PageController::class, 'search']);
    Route::post('/', [PageController::class, 'store']);
    Route::get('/{identifier}', [PageController::class, 'show']);
    Route::put('/{id}', [PageController::class, 'update']);
    Route::delete('/{id}', [PageController::class, 'destroy']);

    // Follow/Like
    Route::post('/{id}/follow', [PageController::class, 'follow']);
    Route::delete('/{id}/follow', [PageController::class, 'unfollow']);
    Route::post('/{id}/like', [PageController::class, 'like']);
    Route::delete('/{id}/like', [PageController::class, 'unlike']);

    // Posts
    Route::get('/{id}/posts', [PageController::class, 'posts']);
    Route::post('/{id}/posts', [PageController::class, 'createPost']);

    // Reviews
    Route::get('/{id}/reviews', [PageController::class, 'reviews']);
    Route::post('/{id}/reviews', [PageController::class, 'addReview']);

    // Roles
    Route::put('/{pageId}/roles/{userId}', [PageController::class, 'updateRole']);
    Route::delete('/{pageId}/roles/{userId}', [PageController::class, 'removeRole']);
});

/*
|--------------------------------------------------------------------------
| Events API Routes
|--------------------------------------------------------------------------
*/
Route::prefix('events')->group(function () {
    Route::get('/', [EventController::class, 'index']);
    Route::get('/categories', [EventController::class, 'categories']);
    Route::get('/user', [EventController::class, 'userEvents']);
    Route::get('/nearby', [EventController::class, 'nearby']);
    Route::get('/search', [EventController::class, 'search']);
    Route::post('/', [EventController::class, 'store']);
    Route::get('/{identifier}', [EventController::class, 'show']);
    Route::put('/{id}', [EventController::class, 'update']);
    Route::delete('/{id}', [EventController::class, 'destroy']);

    // Responses
    Route::post('/{id}/respond', [EventController::class, 'respond']);
    Route::delete('/{id}/respond', [EventController::class, 'removeResponse']);
    Route::get('/{id}/attendees', [EventController::class, 'attendees']);

    // Hosts
    Route::post('/{id}/hosts', [EventController::class, 'addHost']);
    Route::delete('/{id}/hosts', [EventController::class, 'removeHost']);

    // Discussion
    Route::get('/{id}/posts', [EventController::class, 'posts']);
    Route::post('/{id}/posts', [EventController::class, 'createPost']);
});

/*
|--------------------------------------------------------------------------
| Polls API Routes
|--------------------------------------------------------------------------
*/
Route::prefix('polls')->group(function () {
    Route::get('/', [PollController::class, 'index']);
    Route::get('/user', [PollController::class, 'userPolls']);
    Route::post('/', [PollController::class, 'store']);
    Route::get('/{id}', [PollController::class, 'show']);
    Route::put('/{id}', [PollController::class, 'update']);
    Route::delete('/{id}', [PollController::class, 'destroy']);

    // Voting
    Route::post('/{id}/vote', [PollController::class, 'vote']);
    Route::delete('/{id}/vote', [PollController::class, 'unvote']);
    Route::post('/{id}/options', [PollController::class, 'addOption']);
    Route::get('/{id}/voters', [PollController::class, 'allVoters']);
    Route::get('/{id}/options/{optionId}/voters', [PollController::class, 'voters']);
    Route::post('/{id}/end', [PollController::class, 'endPoll']);
    Route::post('/{id}/close', [PollController::class, 'closePoll']);
});

/*
|--------------------------------------------------------------------------
| Stories API Routes
|--------------------------------------------------------------------------
*/
Route::prefix('stories')->group(function () {
    Route::get('/', [StoryController::class, 'index']);
    Route::post('/', [StoryController::class, 'store']);
    Route::get('/{id}', [StoryController::class, 'show']);
    Route::delete('/{id}', [StoryController::class, 'destroy']);
    Route::get('/user/{userId}', [StoryController::class, 'userStories']);
    Route::post('/{id}/view', [StoryController::class, 'view']);
    Route::get('/{id}/viewers', [StoryController::class, 'viewers']);
    Route::post('/{id}/react', [StoryController::class, 'react']);
    Route::post('/{id}/reply', [StoryController::class, 'reply']);

    // Highlights
    Route::get('/highlights/{userId}', [StoryController::class, 'highlights']);
    Route::post('/highlights', [StoryController::class, 'createHighlight']);
    Route::put('/highlights/{id}', [StoryController::class, 'updateHighlight']);
    Route::delete('/highlights/{id}', [StoryController::class, 'deleteHighlight']);
});

/*
|--------------------------------------------------------------------------
| Resumable Uploads API Routes
|--------------------------------------------------------------------------
*/
Route::prefix('uploads')->group(function () {
    Route::post('/init', [App\Http\Controllers\Api\ChunkUploadController::class, 'initUpload']);
    Route::get('/resumable', [App\Http\Controllers\Api\ChunkUploadController::class, 'getResumableUploads']);
    Route::get('/{uploadId}/status', [App\Http\Controllers\Api\ChunkUploadController::class, 'getStatus']);
    Route::post('/{uploadId}/chunk', [App\Http\Controllers\Api\ChunkUploadController::class, 'uploadChunk']);
    Route::post('/{uploadId}/complete', [App\Http\Controllers\Api\ChunkUploadController::class, 'completeUpload']);
    Route::post('/{uploadId}/cancel', [App\Http\Controllers\Api\ChunkUploadController::class, 'cancelUpload']);
});

/*
|--------------------------------------------------------------------------
| Clips API Routes
|--------------------------------------------------------------------------
*/
Route::prefix('clips')->group(function () {
    Route::get('/', [ClipController::class, 'index']);
    Route::post('/', [ClipController::class, 'store']);
    Route::get('/trending', [ClipController::class, 'trending']);
    Route::get('/hashtag/{tag}', [ClipController::class, 'byHashtag']);
    Route::get('/music/{musicId}', [ClipController::class, 'byMusic']);
    Route::get('/user/{userId}', [ClipController::class, 'userClips']);
    Route::get('/{id}', [ClipController::class, 'show']);
    Route::delete('/{id}', [ClipController::class, 'destroy']);

    // Interactions
    Route::post('/{id}/like', [ClipController::class, 'like']);
    Route::delete('/{id}/like', [ClipController::class, 'unlike']);
    Route::post('/{id}/save', [ClipController::class, 'save']);
    Route::delete('/{id}/save', [ClipController::class, 'unsave']);
    Route::post('/{id}/share', [ClipController::class, 'share']);

    // Comments
    Route::get('/{id}/comments', [ClipController::class, 'comments']);
    Route::post('/{id}/comments', [ClipController::class, 'addComment']);
    Route::post('/{clipId}/comments/{commentId}/like', [ClipController::class, 'likeComment']);
});

/*
|--------------------------------------------------------------------------
| Music API Routes
|--------------------------------------------------------------------------
*/
Route::prefix('music')->group(function () {
    // Browse
    Route::get('/', [MusicController::class, 'index']);
    Route::get('/featured', [MusicController::class, 'featured']);
    Route::get('/trending', [MusicController::class, 'trending']);
    Route::get('/search', [MusicController::class, 'search']);
    Route::get('/categories', [MusicController::class, 'categories']);
    Route::get('/category/{slug}', [MusicController::class, 'byCategory']);

    // Artists (must be before /{id} to avoid route conflicts)
    Route::get('/artists', [MusicController::class, 'artists']);
    Route::post('/artists', [MusicController::class, 'storeArtist']);
    Route::get('/artists/{id}', [MusicController::class, 'artist']);
    Route::get('/artists/{id}/tracks', [MusicController::class, 'artistTracks']);

    // User tracks and saved music
    Route::get('/saved/{userId}', [MusicController::class, 'savedMusic']);
    Route::get('/user/{userId}', [MusicController::class, 'userTracks']);

    // Upload (two-step flow)
    Route::post('/extract-metadata', [MusicController::class, 'extractMetadata']);
    Route::post('/finalize-upload', [MusicController::class, 'finalizeUpload']);
    Route::post('/cancel-upload', [MusicController::class, 'cancelUpload']);

    // Chunked/Resumable upload (for large files)
    Route::post('/upload-chunk', [MusicController::class, 'uploadChunk']);
    Route::get('/upload-chunk', [MusicController::class, 'checkChunk']);

    // Legacy single-step upload
    Route::post('/upload', [MusicController::class, 'upload']);

    // Admin create track
    Route::post('/', [MusicController::class, 'store']);

    // Track operations (keep /{id} routes last to avoid conflicts)
    Route::get('/{id}', [MusicController::class, 'show']);
    Route::post('/{id}/save', [MusicController::class, 'saveTrack']);
    Route::delete('/{id}/save', [MusicController::class, 'unsaveTrack']);
    Route::delete('/{id}/delete', [MusicController::class, 'deleteTrack']);
});

/*
|--------------------------------------------------------------------------
| Live Streaming API Routes
|--------------------------------------------------------------------------
*/
Route::prefix('streams')->group(function () {
    Route::get('/', [LiveStreamController::class, 'index']);
    Route::post('/', [LiveStreamController::class, 'store']);
    Route::get('/gifts', [LiveStreamController::class, 'gifts']);
    Route::get('/user/{userId}', [LiveStreamController::class, 'userStreams']);
    Route::get('/{id}', [LiveStreamController::class, 'show']);
    Route::post('/{id}/start', [LiveStreamController::class, 'start']);
    Route::post('/{id}/end', [LiveStreamController::class, 'end']);

    // Viewers
    Route::post('/{id}/join', [LiveStreamController::class, 'join']);
    Route::post('/{id}/leave', [LiveStreamController::class, 'leave']);
    Route::get('/{id}/viewers', [LiveStreamController::class, 'viewers']);
    Route::post('/{id}/like', [LiveStreamController::class, 'like']);

    // Comments
    Route::get('/{id}/comments', [LiveStreamController::class, 'comments']);
    Route::post('/{id}/comments', [LiveStreamController::class, 'addComment']);
    Route::post('/{id}/comments/{commentId}/pin', [LiveStreamController::class, 'pinComment']);

    // Gifts
    Route::post('/{id}/gifts', [LiveStreamController::class, 'sendGift']);
    Route::get('/{id}/gifts/received', [LiveStreamController::class, 'streamGifts']);

    // Co-hosts
    Route::post('/{id}/cohost/invite', [LiveStreamController::class, 'inviteCohost']);
    Route::post('/{id}/cohost/respond', [LiveStreamController::class, 'respondCohost']);
    Route::post('/{id}/cohost/leave', [LiveStreamController::class, 'leaveCohost']);
});

/*
|--------------------------------------------------------------------------
| Calls API Routes
|--------------------------------------------------------------------------
*/
Route::prefix('calls')->group(function () {
    Route::post('/', [CallController::class, 'initiate']);
    Route::get('/incoming/{userId}', [CallController::class, 'incoming']);
    Route::get('/history/{userId}', [CallController::class, 'history']);
    Route::get('/{id}', [CallController::class, 'show']);
    Route::post('/{id}/answer', [CallController::class, 'answer']);
    Route::post('/{id}/decline', [CallController::class, 'decline']);
    Route::post('/{id}/end', [CallController::class, 'end']);
    Route::post('/{id}/missed', [CallController::class, 'markMissed']);

    // Group calls
    Route::post('/group', [CallController::class, 'startGroupCall']);
    Route::get('/group/active/{conversationId}', [CallController::class, 'getActiveGroupCall']);
    Route::post('/group/{id}/join', [CallController::class, 'joinGroupCall']);
    Route::post('/group/{id}/leave', [CallController::class, 'leaveGroupCall']);
    Route::post('/group/{id}/decline', [CallController::class, 'declineGroupCall']);
    Route::post('/group/{id}/end', [CallController::class, 'endGroupCall']);
    Route::post('/group/{id}/mute', [CallController::class, 'toggleMute']);
    Route::post('/group/{id}/video', [CallController::class, 'toggleVideo']);
});

/*
|--------------------------------------------------------------------------
| Wallet (Tajiri Pay) API Routes
|--------------------------------------------------------------------------
*/
Route::prefix('wallet')->group(function () {
    Route::get('/{userId}', [WalletController::class, 'getWallet']);
    Route::post('/{userId}/pin', [WalletController::class, 'setPin']);
    Route::put('/{userId}/pin', [WalletController::class, 'changePin']);
    Route::get('/{userId}/transactions', [WalletController::class, 'transactions']);
    Route::post('/{userId}/deposit', [WalletController::class, 'deposit']);
    Route::post('/{userId}/withdraw', [WalletController::class, 'withdraw']);
    Route::post('/{userId}/transfer', [WalletController::class, 'transfer']);

    // Mobile money accounts
    Route::get('/{userId}/mobile-accounts', [WalletController::class, 'getMobileAccounts']);
    Route::post('/{userId}/mobile-accounts', [WalletController::class, 'linkMobileAccount']);
    Route::delete('/mobile-accounts/{accountId}', [WalletController::class, 'removeMobileAccount']);

    // Payment requests
    Route::get('/{userId}/payment-requests', [WalletController::class, 'getPaymentRequests']);
    Route::post('/{userId}/payment-requests', [WalletController::class, 'requestPayment']);
    Route::post('/payment-requests/{requestId}/pay', [WalletController::class, 'payRequest']);
    Route::post('/payment-requests/{requestId}/decline', [WalletController::class, 'declineRequest']);
    Route::post('/payment-requests/{requestId}/cancel', [WalletController::class, 'cancelRequest']);
});

/*
|--------------------------------------------------------------------------
| Subscriptions & Creator Monetization API Routes
|--------------------------------------------------------------------------
*/
Route::prefix('subscriptions')->group(function () {
    // Tiers
    Route::get('/tiers/{creatorId}', [SubscriptionController::class, 'getTiers']);
    Route::post('/tiers', [SubscriptionController::class, 'createTier']);
    Route::put('/tiers/{id}', [SubscriptionController::class, 'updateTier']);

    // Subscriptions
    Route::post('/', [SubscriptionController::class, 'subscribe']);
    Route::get('/user/{userId}', [SubscriptionController::class, 'mySubscriptions']);
    Route::get('/creator/{creatorId}/subscribers', [SubscriptionController::class, 'getSubscribers']);
    Route::get('/check/{subscriberId}/{creatorId}', [SubscriptionController::class, 'checkSubscription']);
    Route::post('/{id}/cancel', [SubscriptionController::class, 'cancel']);

    // Tips
    Route::post('/tips', [SubscriptionController::class, 'sendTip']);

    // Earnings
    Route::get('/earnings/{creatorId}', [SubscriptionController::class, 'getEarnings']);

    // Payouts
    Route::get('/payouts/{creatorId}', [SubscriptionController::class, 'getPayouts']);
    Route::post('/payouts', [SubscriptionController::class, 'requestPayout']);
});
