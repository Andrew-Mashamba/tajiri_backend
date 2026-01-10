<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ConversationController extends Controller
{
    /**
     * Get user's conversations.
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

        $conversations = Conversation::whereHas('participants', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })
            ->with([
                'lastMessage:id,conversation_id,sender_id,content,message_type,created_at',
                'lastMessage.sender:id,first_name,last_name',
                'participants' => function ($q) use ($userId) {
                    $q->where('user_id', '!=', $userId)
                        ->select('user_profiles.id', 'first_name', 'last_name', 'username', 'profile_photo_path');
                },
            ])
            ->withCount(['participantRecords as unread_count' => function ($q) use ($userId) {
                $q->where('user_id', $userId);
            }])
            ->orderBy('last_message_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        // Format conversations for display
        $formattedConversations = $conversations->getCollection()->map(function ($conv) use ($userId) {
            $participant = ConversationParticipant::where('conversation_id', $conv->id)
                ->where('user_id', $userId)
                ->first();

            // For private chats, get the other person's info
            if ($conv->type === Conversation::TYPE_PRIVATE && $conv->participants->count() > 0) {
                $otherUser = $conv->participants->first();
                $conv->display_name = $otherUser->full_name;
                $conv->display_photo = $otherUser->profile_photo_url;
            } else {
                $conv->display_name = $conv->name;
                $conv->display_photo = $conv->avatar_path ? asset('storage/' . $conv->avatar_path) : null;
            }

            $conv->unread_count = $participant?->unread_count ?? 0;
            $conv->is_muted = $participant?->is_muted ?? false;

            return $conv;
        });

        return response()->json([
            'success' => true,
            'data' => $formattedConversations,
            'meta' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'per_page' => $conversations->perPage(),
                'total' => $conversations->total(),
            ],
        ]);
    }

    /**
     * Create a new group conversation.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'participant_ids' => 'required|array|min:1',
            'participant_ids.*' => 'exists:user_profiles,id',
            'name' => 'required_if:type,group|string|max:100',
            'type' => 'in:private,group',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = $request->user_id;
        $participantIds = array_unique(array_merge([$userId], $request->participant_ids));
        $type = $request->type ?? 'group';

        // For private chats, use getOrCreatePrivate
        if ($type === 'private' && count($participantIds) === 2) {
            $otherUserId = array_values(array_diff($participantIds, [$userId]))[0];
            $conversation = Conversation::getOrCreatePrivate($userId, $otherUserId);

            return response()->json([
                'success' => true,
                'message' => 'Conversation ready',
                'data' => $this->formatConversation($conversation, $userId),
            ]);
        }

        // Create group conversation
        try {
            DB::beginTransaction();

            $conversation = Conversation::create([
                'type' => Conversation::TYPE_GROUP,
                'name' => $request->name,
                'created_by' => $userId,
            ]);

            // Add participants
            foreach ($participantIds as $participantId) {
                ConversationParticipant::create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $participantId,
                    'is_admin' => $participantId === $userId,
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Group created successfully',
                'data' => $this->formatConversation($conversation->fresh(), $userId),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create conversation: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get or create a private conversation with another user.
     */
    public function getPrivate(Request $request, int $otherUserId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = $request->user_id;

        if (!UserProfile::find($otherUserId)) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        $conversation = Conversation::getOrCreatePrivate($userId, $otherUserId);

        return response()->json([
            'success' => true,
            'data' => $this->formatConversation($conversation, $userId),
        ]);
    }

    /**
     * Get a single conversation with messages.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $userId = $request->query('user_id');

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User ID is required',
            ], 400);
        }

        $conversation = Conversation::with([
            'participants:id,first_name,last_name,username,profile_photo_path',
        ])->find($id);

        if (!$conversation) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found',
            ], 404);
        }

        // Verify user is a participant
        if (!$conversation->hasParticipant($userId)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not a participant in this conversation',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatConversation($conversation, $userId),
        ]);
    }

    /**
     * Get messages in a conversation.
     */
    public function getMessages(Request $request, int $id): JsonResponse
    {
        $userId = $request->query('user_id');
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 50);
        $before = $request->query('before'); // Message ID for pagination

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User ID is required',
            ], 400);
        }

        $conversation = Conversation::find($id);

        if (!$conversation) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found',
            ], 404);
        }

        if (!$conversation->hasParticipant($userId)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not a participant in this conversation',
            ], 403);
        }

        $query = Message::where('conversation_id', $id)
            ->with('sender:id,first_name,last_name,username,profile_photo_path')
            ->orderBy('created_at', 'desc');

        if ($before) {
            $query->where('id', '<', $before);
        }

        $messages = $query->paginate($perPage, ['*'], 'page', $page);

        // Mark as read
        $participant = ConversationParticipant::where('conversation_id', $id)
            ->where('user_id', $userId)
            ->first();

        if ($participant) {
            $participant->markAsRead();
        }

        return response()->json([
            'success' => true,
            'data' => array_reverse($messages->items()),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ],
        ]);
    }

    /**
     * Send a message in a conversation.
     */
    public function sendMessage(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'content' => 'required_without:media|nullable|string|max:5000',
            'message_type' => 'in:text,image,video,audio,document,location,contact',
            'media' => 'nullable|file|max:51200',
            'reply_to_id' => 'nullable|exists:messages,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = $request->user_id;
        $conversation = Conversation::find($id);

        if (!$conversation) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found',
            ], 404);
        }

        if (!$conversation->hasParticipant($userId)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not a participant in this conversation',
            ], 403);
        }

        try {
            DB::beginTransaction();

            $mediaPath = null;
            $mediaType = null;
            $messageType = $request->message_type ?? 'text';

            // Handle file upload
            if ($request->hasFile('media')) {
                $file = $request->file('media');
                $mediaPath = $file->store("messages/{$id}", 'public');
                $mediaType = $file->getMimeType();
                $messageType = $this->getMessageTypeFromMime($mediaType);
            }

            $message = Message::create([
                'conversation_id' => $id,
                'sender_id' => $userId,
                'content' => $request->content,
                'message_type' => $messageType,
                'media_path' => $mediaPath,
                'media_type' => $mediaType,
                'reply_to_id' => $request->reply_to_id,
            ]);

            // Update conversation
            $conversation->updateLastMessage($message);

            // Increment unread count for other participants
            ConversationParticipant::where('conversation_id', $id)
                ->where('user_id', '!=', $userId)
                ->increment('unread_count');

            // Mark sender's conversation as read
            ConversationParticipant::where('conversation_id', $id)
                ->where('user_id', $userId)
                ->update(['last_read_at' => now(), 'unread_count' => 0]);

            DB::commit();

            $message->load('sender:id,first_name,last_name,username,profile_photo_path');

            return response()->json([
                'success' => true,
                'message' => 'Message sent',
                'data' => $message,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to send message: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mark conversation as read.
     */
    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $participant = ConversationParticipant::where('conversation_id', $id)
            ->where('user_id', $request->user_id)
            ->first();

        if (!$participant) {
            return response()->json([
                'success' => false,
                'message' => 'Not a participant',
            ], 403);
        }

        $participant->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'Marked as read',
        ]);
    }

    /**
     * Leave or delete a conversation.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = $request->user_id;
        $conversation = Conversation::find($id);

        if (!$conversation) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found',
            ], 404);
        }

        if (!$conversation->hasParticipant($userId)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not a participant',
            ], 403);
        }

        try {
            DB::beginTransaction();

            if ($conversation->isPrivate()) {
                // For private chats, just remove the participant
                ConversationParticipant::where('conversation_id', $id)
                    ->where('user_id', $userId)
                    ->delete();
            } else {
                // For groups, remove participant
                ConversationParticipant::where('conversation_id', $id)
                    ->where('user_id', $userId)
                    ->delete();

                // If no participants left, delete conversation
                if ($conversation->participants()->count() === 0) {
                    // Delete messages and media
                    foreach ($conversation->messages as $message) {
                        if ($message->media_path) {
                            Storage::disk('public')->delete($message->media_path);
                        }
                    }
                    $conversation->delete();
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Left conversation',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to leave conversation: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get total unread message count for a user.
     */
    public function getUnreadCount(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User ID is required',
            ], 400);
        }

        $count = ConversationParticipant::where('user_id', $userId)
            ->sum('unread_count');

        return response()->json([
            'success' => true,
            'data' => ['unread_count' => $count],
        ]);
    }

    /**
     * Format conversation for response.
     */
    private function formatConversation(Conversation $conversation, int $userId): array
    {
        $conversation->load('participants:id,first_name,last_name,username,profile_photo_path');

        $participant = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $userId)
            ->first();

        $data = $conversation->toArray();

        if ($conversation->isPrivate()) {
            $otherUser = $conversation->getOtherParticipant($userId);
            if ($otherUser) {
                $data['display_name'] = $otherUser->full_name;
                $data['display_photo'] = $otherUser->profile_photo_url;
            }
        } else {
            $data['display_name'] = $conversation->name;
            $data['display_photo'] = $conversation->avatar_path ? asset('storage/' . $conversation->avatar_path) : null;
        }

        $data['unread_count'] = $participant?->unread_count ?? 0;
        $data['is_muted'] = $participant?->is_muted ?? false;
        $data['is_admin'] = $participant?->is_admin ?? false;

        return $data;
    }

    /**
     * Get message type from MIME type.
     */
    private function getMessageTypeFromMime(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }
        if (str_starts_with($mimeType, 'audio/')) {
            return 'audio';
        }
        return 'document';
    }

    /**
     * Start typing indicator.
     */
    public function startTyping(Request $request, int $id): JsonResponse
    {
        $userId = $request->input('user_id');

        $participant = ConversationParticipant::where('conversation_id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$participant) {
            return response()->json([
                'success' => false,
                'message' => 'Not a participant',
            ], 403);
        }

        $participant->startTyping();

        return response()->json([
            'success' => true,
        ]);
    }

    /**
     * Stop typing indicator.
     */
    public function stopTyping(Request $request, int $id): JsonResponse
    {
        $userId = $request->input('user_id');

        $participant = ConversationParticipant::where('conversation_id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$participant) {
            return response()->json([
                'success' => false,
                'message' => 'Not a participant',
            ], 403);
        }

        $participant->stopTyping();

        return response()->json([
            'success' => true,
        ]);
    }

    /**
     * Get typing status of other participants.
     */
    public function getTypingStatus(Request $request, int $id): JsonResponse
    {
        $userId = $request->query('user_id');

        $typingUsers = ConversationParticipant::where('conversation_id', $id)
            ->where('user_id', '!=', $userId)
            ->where('is_typing', true)
            ->where('typing_started_at', '>', now()->subSeconds(5))
            ->with('user:id,first_name,last_name')
            ->get()
            ->map(function ($p) {
                return [
                    'user_id' => $p->user_id,
                    'name' => $p->user->first_name,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $typingUsers,
        ]);
    }
}
