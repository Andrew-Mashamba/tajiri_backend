<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventResponse;
use App\Models\EventHost;
use App\Models\EventPost;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class EventController extends Controller
{
    /**
     * Get list of events.
     */
    public function index(Request $request): JsonResponse
    {
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);
        $type = $request->query('type', 'upcoming'); // upcoming, past, all
        $category = $request->query('category');
        $currentUserId = $request->query('current_user_id');

        $query = Event::public()
            ->with(['creator:id,first_name,last_name,username,profile_photo_path']);

        if ($type === 'upcoming') {
            $query->upcoming();
        } elseif ($type === 'past') {
            $query->past();
        }

        if ($category) {
            $query->where('category', $category);
        }

        $events = $query->paginate($perPage, ['*'], 'page', $page);

        if ($currentUserId) {
            foreach ($events as $event) {
                $event->user_response = $event->getUserResponse($currentUserId);
                $event->is_host = $event->isHost($currentUserId);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $events->items(),
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ],
        ]);
    }

    /**
     * Get event categories.
     */
    public function categories(): JsonResponse
    {
        $categories = [
            ['value' => 'social', 'label' => 'Kijamii'],
            ['value' => 'business', 'label' => 'Biashara'],
            ['value' => 'education', 'label' => 'Elimu'],
            ['value' => 'entertainment', 'label' => 'Burudani'],
            ['value' => 'sports', 'label' => 'Michezo'],
            ['value' => 'health', 'label' => 'Afya'],
            ['value' => 'religious', 'label' => 'Dini'],
            ['value' => 'political', 'label' => 'Siasa'],
            ['value' => 'other', 'label' => 'Nyingine'],
        ];

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    /**
     * Get user's events (going/interested/hosting).
     */
    public function userEvents(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        $filter = $request->query('filter', 'going'); // going, interested, hosting

        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'User ID required'], 400);
        }

        $query = Event::with(['creator:id,first_name,last_name,username,profile_photo_path']);

        if ($filter === 'hosting') {
            $query->where('creator_id', $userId);
        } else {
            $query->whereHas('responses', function ($q) use ($userId, $filter) {
                $q->where('user_id', $userId)->where('response', $filter);
            });
        }

        $events = $query->upcoming()->get();

        return response()->json([
            'success' => true,
            'data' => $events,
        ]);
    }

    /**
     * Create a new event.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'creator_id' => 'required|exists:user_profiles,id',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:5000',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'is_all_day' => 'boolean',
            'location_name' => 'nullable|string|max:255',
            'location_address' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'is_online' => 'boolean',
            'online_link' => 'nullable|url|max:255',
            'privacy' => 'in:public,friends,private,group',
            'category' => 'nullable|string|max:50',
            'group_id' => 'nullable|exists:groups,id',
            'page_id' => 'nullable|exists:pages,id',
            'ticket_price' => 'nullable|numeric|min:0',
            'ticket_currency' => 'nullable|string|max:10',
            'ticket_link' => 'nullable|url|max:255',
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

            $coverPath = null;
            if ($request->hasFile('cover_photo')) {
                $coverPath = $request->file('cover_photo')->store('events/covers', 'public');
            }

            $event = Event::create([
                'name' => $request->name,
                'slug' => Str::slug($request->name) . '-' . Str::random(6),
                'description' => $request->description,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'start_time' => $request->start_time,
                'end_time' => $request->end_time,
                'timezone' => $request->timezone ?? 'Africa/Dar_es_Salaam',
                'is_all_day' => $request->is_all_day ?? false,
                'location_name' => $request->location_name,
                'location_address' => $request->location_address,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'is_online' => $request->is_online ?? false,
                'online_link' => $request->online_link,
                'privacy' => $request->privacy ?? 'public',
                'category' => $request->category,
                'creator_id' => $request->creator_id,
                'group_id' => $request->group_id,
                'page_id' => $request->page_id,
                'ticket_price' => $request->ticket_price,
                'ticket_currency' => $request->ticket_currency ?? 'TZS',
                'ticket_link' => $request->ticket_link,
                'cover_photo_path' => $coverPath,
                'going_count' => 1, // Creator is automatically going
            ]);

            // Add creator as host
            EventHost::create([
                'event_id' => $event->id,
                'user_id' => $request->creator_id,
                'is_primary' => true,
            ]);

            // Creator is automatically "going"
            EventResponse::create([
                'event_id' => $event->id,
                'user_id' => $request->creator_id,
                'response' => Event::RESPONSE_GOING,
            ]);

            DB::commit();

            $event->load('creator:id,first_name,last_name,username,profile_photo_path');

            return response()->json([
                'success' => true,
                'message' => 'Event created successfully',
                'data' => $event,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create event: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a single event.
     */
    public function show(string $identifier, Request $request): JsonResponse
    {
        $event = Event::where('id', $identifier)
            ->orWhere('slug', $identifier)
            ->with([
                'creator:id,first_name,last_name,username,profile_photo_path',
                'group:id,name,slug,cover_photo_path',
                'page:id,name,slug,profile_photo_path',
            ])
            ->first();

        if (!$event) {
            return response()->json([
                'success' => false,
                'message' => 'Event not found',
            ], 404);
        }

        $currentUserId = $request->query('current_user_id');
        if ($currentUserId) {
            $event->user_response = $event->getUserResponse($currentUserId);
            $event->is_host = $event->isHost($currentUserId);
        }

        return response()->json([
            'success' => true,
            'data' => $event,
        ]);
    }

    /**
     * Update an event.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $event = Event::find($id);
        if (!$event) {
            return response()->json(['success' => false, 'message' => 'Event not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'string|max:100',
            'description' => 'nullable|string|max:5000',
            'start_date' => 'date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'is_all_day' => 'boolean',
            'location_name' => 'nullable|string|max:255',
            'location_address' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'is_online' => 'boolean',
            'online_link' => 'nullable|url|max:255',
            'privacy' => 'in:public,friends,private,group',
            'category' => 'nullable|string|max:50',
            'ticket_price' => 'nullable|numeric|min:0',
            'ticket_currency' => 'nullable|string|max:10',
            'ticket_link' => 'nullable|url|max:255',
            'cover_photo' => 'nullable|image|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->hasFile('cover_photo')) {
            if ($event->cover_photo_path) {
                Storage::disk('public')->delete($event->cover_photo_path);
            }
            $event->cover_photo_path = $request->file('cover_photo')->store('events/covers', 'public');
        }

        $event->update($request->only([
            'name', 'description', 'start_date', 'end_date', 'start_time', 'end_time',
            'is_all_day', 'location_name', 'location_address', 'latitude', 'longitude',
            'is_online', 'online_link', 'privacy', 'category', 'ticket_price',
            'ticket_currency', 'ticket_link'
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Event updated successfully',
            'data' => $event->fresh(['creator:id,first_name,last_name,username,profile_photo_path']),
        ]);
    }

    /**
     * Delete an event.
     */
    public function destroy(int $id): JsonResponse
    {
        $event = Event::find($id);
        if (!$event) {
            return response()->json(['success' => false, 'message' => 'Event not found'], 404);
        }

        if ($event->cover_photo_path) {
            Storage::disk('public')->delete($event->cover_photo_path);
        }

        $event->delete();

        return response()->json([
            'success' => true,
            'message' => 'Event deleted successfully',
        ]);
    }

    /**
     * Respond to an event (going, interested, not_going).
     */
    public function respond(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'response' => 'required|in:going,interested,not_going',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $event = Event::find($id);
        if (!$event) {
            return response()->json(['success' => false, 'message' => 'Event not found'], 404);
        }

        $existingResponse = EventResponse::where('event_id', $id)
            ->where('user_id', $request->user_id)
            ->first();

        $oldResponse = $existingResponse?->response;

        if ($existingResponse) {
            $existingResponse->update(['response' => $request->response]);
        } else {
            EventResponse::create([
                'event_id' => $id,
                'user_id' => $request->user_id,
                'response' => $request->response,
            ]);
        }

        // Update counts
        $event->updateResponseCounts($oldResponse, $request->response);

        $event->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Response recorded',
            'data' => [
                'going_count' => $event->going_count,
                'interested_count' => $event->interested_count,
                'not_going_count' => $event->not_going_count,
            ],
        ]);
    }

    /**
     * Remove response to an event.
     */
    public function removeResponse(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $event = Event::find($id);
        if (!$event) {
            return response()->json(['success' => false, 'message' => 'Event not found'], 404);
        }

        $response = EventResponse::where('event_id', $id)
            ->where('user_id', $request->user_id)
            ->first();

        if (!$response) {
            return response()->json(['success' => false, 'message' => 'No response found'], 400);
        }

        $oldResponse = $response->response;
        $response->delete();

        $event->updateResponseCounts($oldResponse, null);

        return response()->json([
            'success' => true,
            'message' => 'Response removed',
        ]);
    }

    /**
     * Get event attendees.
     */
    public function attendees(Request $request, int $id): JsonResponse
    {
        $event = Event::find($id);
        if (!$event) {
            return response()->json(['success' => false, 'message' => 'Event not found'], 404);
        }

        $responseType = $request->query('type', 'going'); // going, interested

        $attendees = $event->responses()
            ->where('response', $responseType)
            ->with('user:id,first_name,last_name,username,profile_photo_path')
            ->get()
            ->pluck('user');

        return response()->json([
            'success' => true,
            'data' => $attendees,
        ]);
    }

    /**
     * Add co-host to event.
     */
    public function addHost(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|exists:user_profiles,id',
            'page_id' => 'nullable|exists:pages,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $event = Event::find($id);
        if (!$event) {
            return response()->json(['success' => false, 'message' => 'Event not found'], 404);
        }

        if (!$request->user_id && !$request->page_id) {
            return response()->json(['success' => false, 'message' => 'User ID or Page ID required'], 400);
        }

        EventHost::create([
            'event_id' => $id,
            'user_id' => $request->user_id,
            'page_id' => $request->page_id,
            'is_primary' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Co-host added',
        ]);
    }

    /**
     * Remove co-host from event.
     */
    public function removeHost(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|exists:user_profiles,id',
            'page_id' => 'nullable|exists:pages,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $event = Event::find($id);
        if (!$event) {
            return response()->json(['success' => false, 'message' => 'Event not found'], 404);
        }

        $query = EventHost::where('event_id', $id)->where('is_primary', false);

        if ($request->user_id) {
            $query->where('user_id', $request->user_id);
        } elseif ($request->page_id) {
            $query->where('page_id', $request->page_id);
        }

        $query->delete();

        return response()->json([
            'success' => true,
            'message' => 'Co-host removed',
        ]);
    }

    /**
     * Get event discussion posts.
     */
    public function posts(Request $request, int $id): JsonResponse
    {
        $event = Event::find($id);
        if (!$event) {
            return response()->json(['success' => false, 'message' => 'Event not found'], 404);
        }

        $pageNum = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);

        $posts = $event->posts()
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
     * Create a post in event discussion.
     */
    public function createPost(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'content' => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $event = Event::find($id);
        if (!$event) {
            return response()->json(['success' => false, 'message' => 'Event not found'], 404);
        }

        try {
            DB::beginTransaction();

            $post = Post::create([
                'user_id' => $request->user_id,
                'content' => $request->content,
                'post_type' => 'text',
                'privacy' => 'public',
            ]);

            EventPost::create([
                'event_id' => $id,
                'post_id' => $post->id,
            ]);

            DB::commit();

            $post->load('user:id,first_name,last_name,username,profile_photo_path');

            return response()->json([
                'success' => true,
                'message' => 'Post created',
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
     * Get nearby events.
     */
    public function nearby(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'radius' => 'nullable|numeric|min:1|max:100', // km
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $lat = $request->latitude;
        $lng = $request->longitude;
        $radius = $request->radius ?? 50; // Default 50km

        // Haversine formula for distance calculation
        $events = Event::public()
            ->upcoming()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->selectRaw("*, (6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) AS distance", [$lat, $lng, $lat])
            ->having('distance', '<', $radius)
            ->orderBy('distance')
            ->limit(20)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $events,
        ]);
    }

    /**
     * Search events.
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->query('q');
        if (!$query) {
            return response()->json(['success' => false, 'message' => 'Query required'], 400);
        }

        $events = Event::public()
            ->where(function ($q) use ($query) {
                $q->where('name', 'LIKE', "%{$query}%")
                  ->orWhere('description', 'LIKE', "%{$query}%")
                  ->orWhere('location_name', 'LIKE', "%{$query}%");
            })
            ->upcoming()
            ->with(['creator:id,first_name,last_name,username,profile_photo_path'])
            ->limit(20)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $events,
        ]);
    }
}
