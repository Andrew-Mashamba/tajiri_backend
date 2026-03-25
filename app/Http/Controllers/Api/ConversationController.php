<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Events\MessageDeleted;
use App\Events\MessageUpdated;
use App\Events\NewMessage;
use App\Events\RecordingIndicator;
use App\Events\TypingIndicator;
use App\Jobs\SendMessageNotification;
use App\Models\BlockedUser;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\Report;
use App\Models\UserPresence;
use App\Models\UserProfile;
use App\Exceptions\UserBlockedException;
use App\Services\Firebase\FirebaseLiveUpdateService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ConversationController extends Controller
{
    protected FirebaseLiveUpdateService $firebaseLiveUpdate;

    public function __construct(FirebaseLiveUpdateService $firebaseLiveUpdate)
    {
        $this->firebaseLiveUpdate = $firebaseLiveUpdate;
    }

    /**
     * Get user's conversations.
     *
     * Query params:
     *   user_id          – required
     *   type             – "private" | "group" (filter by conversation type)
     *   include_groups   – "1" to include group conversations (default: included)
     *   search, group_id, filter, folder, page, per_page – existing filters
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);
        $search = $request->query('search');
        $groupId = $request->query('group_id');
        $filter = $request->query('filter'); // pinned, favorites, archived, unread
        $folder = $request->query('folder');
        $type = $request->query('type'); // "private" or "group"

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User ID is required',
            ], 400);
        }

        // Get blocked user IDs to filter out
        $blockedIds = BlockedUser::getAllBlockedIds((int) $userId);

        $query = Conversation::whereHas('participants', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })
            ->with([
                'lastMessage:id,conversation_id,sender_id,content,message_type,media_path,media_type,reply_to_id,is_read,read_at,created_at,updated_at',
                'lastMessage.sender:id,first_name,last_name,username,profile_photo_path',
                'participants' => function ($q) use ($userId) {
                    $q->where('user_id', '!=', $userId)
                        ->select('user_profiles.id', 'first_name', 'last_name', 'username', 'profile_photo_path');
                },
            ]);

        // Filter by conversation type if specified
        if ($type === 'private') {
            $query->where('type', Conversation::TYPE_PRIVATE);
        } elseif ($type === 'group') {
            $query->where('type', Conversation::TYPE_GROUP);
        }
        // include_groups=1 is accepted but groups are included by default

        // Filter out conversations where the only other participant is blocked (for private chats)
        if (!empty($blockedIds)) {
            $query->where(function ($q) use ($blockedIds) {
                // Exclude private conversations with blocked users
                $q->where('type', '!=', 'private')
                    ->orWhereDoesntHave('participantRecords', function ($sub) use ($blockedIds) {
                        $sub->whereIn('user_id', $blockedIds);
                    });
            });
        }

        // Filter by group_id
        if ($groupId) {
            $query->where('group_id', $groupId);
        }

        // Search by conversation name or participant name
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhereHas('participants', function ($sub) use ($search) {
                        $sub->where('first_name', 'ilike', "%{$search}%")
                            ->orWhere('last_name', 'ilike', "%{$search}%")
                            ->orWhere('username', 'ilike', "%{$search}%");
                    });
            });
        }

        // Apply filter based on participant state
        if ($filter || $folder) {
            $query->whereHas('participantRecords', function ($q) use ($userId, $filter, $folder) {
                $q->where('user_id', $userId);
                if ($filter === 'pinned') {
                    $q->where('is_pinned', true);
                } elseif ($filter === 'favorites') {
                    $q->where('is_favorite', true);
                } elseif ($filter === 'archived') {
                    $q->where('is_archived', true);
                } elseif ($filter === 'unread') {
                    $q->where('unread_count', '>', 0);
                }
                if ($folder) {
                    $q->where('folder', $folder);
                }
            });
        }

        // By default exclude archived conversations unless explicitly filtering for them
        if ($filter !== 'archived') {
            $query->whereDoesntHave('participantRecords', function ($q) use ($userId) {
                $q->where('user_id', $userId)->where('is_archived', true);
            });
        }

        // Pinned conversations first, then by last_message_at
        $conversations = $query->orderByRaw(
            'EXISTS (SELECT 1 FROM conversation_participants WHERE conversation_participants.conversation_id = conversations.id AND conversation_participants.user_id = ? AND conversation_participants.is_pinned = true) DESC',
            [$userId]
        )
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
                $conv->display_photo = $otherUser->profile_photo_path;
            } else {
                $conv->display_name = $conv->name;
                $conv->display_photo = $conv->avatar_path;
            }

            $conv->unread_count = $participant?->unread_count ?? 0;
            $conv->is_muted = $participant?->is_muted ?? false;
            $conv->is_pinned = $participant?->is_pinned ?? false;
            $conv->is_favorite = $participant?->is_favorite ?? false;
            $conv->is_archived = $participant?->is_archived ?? false;
            $conv->folder = $participant?->folder;
            $conv->is_admin = $participant?->is_admin ?? false;

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

            // Check who_can_message privacy
            $otherUser = UserProfile::find($otherUserId);
            if ($otherUser && !$otherUser->canBeMessagedBy((int) $userId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This user does not accept messages from you',
                ], 403);
            }

            try {
                $conversation = Conversation::getOrCreatePrivate($userId, $otherUserId);
            } catch (UserBlockedException $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 403);
            }

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
                    'is_admin' => $participantId == $userId,
                ]);
            }

            DB::commit();

            // Firebase: notify all participants (except creator) about new conversation
            $this->firebaseLiveUpdate->notifyConversationParticipants($conversation->id, (int) $userId, 'messages_updated');

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

        $otherUser = UserProfile::find($otherUserId);
        if (!$otherUser) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        // Check who_can_message privacy
        if (!$otherUser->canBeMessagedBy((int) $userId)) {
            return response()->json([
                'success' => false,
                'message' => 'This user does not accept messages from you',
            ], 403);
        }

        try {
            $conversation = Conversation::getOrCreatePrivate($userId, $otherUserId);
        } catch (UserBlockedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);
        }

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
            ->with([
                'sender:id,first_name,last_name,username,profile_photo_path',
                'replyTo:id,conversation_id,sender_id,content,message_type',
                'replyTo.sender:id,first_name,last_name',
                'reactions',
            ])
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
            'message_type' => 'in:text,image,video,audio,document,location,contact,shared_post',
            'media' => 'nullable|file|max:51200',
            'reply_to_id' => 'nullable|exists:messages,id',
            'forward_message_id' => 'nullable|exists:messages,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = (int) $request->user_id;
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

        // Check block status for private conversations
        if ($conversation->isPrivate()) {
            $otherUser = $conversation->getOtherParticipant($userId);
            if ($otherUser && BlockedUser::isEitherBlocked($userId, $otherUser->id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot send message to this user',
                ], 403);
            }
        }

        try {
            DB::beginTransaction();

            $mediaPath = null;
            $mediaType = null;
            $messageType = $request->message_type ?? 'text';
            $content = $request->content;

            // Handle forwarding: copy original message data
            if ($request->forward_message_id) {
                $originalMessage = Message::find($request->forward_message_id);
                if ($originalMessage) {
                    $content = $content ?? $originalMessage->content;
                    $messageType = $originalMessage->message_type;
                    $mediaPath = $originalMessage->media_path;
                    $mediaType = $originalMessage->media_type;
                }
            }

            // Handle file upload (overrides forwarded media)
            if ($request->hasFile('media')) {
                $file = $request->file('media');
                $mediaPath = $file->store("messages/{$id}", 'public');
                $mediaType = $file->getMimeType();
                $messageType = $this->getMessageTypeFromMime($mediaType);
            }

            $message = Message::create([
                'conversation_id' => $id,
                'sender_id' => $userId,
                'content' => $content,
                'message_type' => $messageType,
                'media_path' => $mediaPath,
                'media_type' => $mediaType,
                'reply_to_id' => $request->reply_to_id,
                'forward_message_id' => $request->forward_message_id,
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

            // Firebase: notify other participants about new message
            $this->firebaseLiveUpdate->notifyConversationParticipants($id, $userId, 'messages_updated');

            $message->load([
                'sender:id,first_name,last_name,username,profile_photo_path',
                'replyTo:id,conversation_id,sender_id,content,message_type',
                'replyTo.sender:id,first_name,last_name',
                'reactions',
            ]);

            // Broadcast via WebSocket for real-time delivery
            broadcast(new NewMessage($message))->toOthers();

            // Send FCM push notifications to offline/background users
            SendMessageNotification::dispatch($message);

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
     * Edit a message.
     */
    public function editMessage(Request $request, int $id, int $messageId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'content' => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $message = Message::where('conversation_id', $id)
            ->where('id', $messageId)
            ->first();

        if (!$message) {
            return response()->json([
                'success' => false,
                'message' => 'Message not found',
            ], 404);
        }

        if ($message->sender_id != $request->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'You can only edit your own messages',
            ], 403);
        }

        $message->update([
            'content' => $request->content,
            'edited_at' => now(),
        ]);

        $message->load([
            'sender:id,first_name,last_name,username,profile_photo_path',
            'reactions',
        ]);

        // Firebase notify
        $this->firebaseLiveUpdate->notifyConversationParticipants($id, (int) $request->user_id, 'messages_updated');

        // Broadcast edit via WebSocket
        broadcast(new MessageUpdated($message, 'edited'))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'Message updated',
            'data' => $message,
        ]);
    }

    /**
     * Delete a message.
     */
    public function deleteMessage(Request $request, int $id, int $messageId): JsonResponse
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

        $message = Message::where('conversation_id', $id)
            ->where('id', $messageId)
            ->first();

        if (!$message) {
            return response()->json([
                'success' => false,
                'message' => 'Message not found',
            ], 404);
        }

        if ($message->sender_id != $request->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'You can only delete your own messages',
            ], 403);
        }

        // Clean up media if exists
        if ($message->media_path) {
            Storage::disk('public')->delete($message->media_path);
        }

        $messageId = $message->id;
        $message->delete(); // soft delete

        // Firebase notify
        $this->firebaseLiveUpdate->notifyConversationParticipants($id, (int) $request->user_id, 'messages_updated');

        // Broadcast delete via WebSocket
        broadcast(new MessageDeleted($id, $messageId, (int) $request->user_id))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'Message deleted',
        ]);
    }

    /**
     * Add or toggle a reaction on a message.
     */
    public function addReaction(Request $request, int $id, int $messageId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'emoji' => 'required|string|max:32',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $message = Message::where('conversation_id', $id)
            ->where('id', $messageId)
            ->first();

        if (!$message) {
            return response()->json([
                'success' => false,
                'message' => 'Message not found',
            ], 404);
        }

        // Verify participant
        $conversation = Conversation::find($id);
        if (!$conversation || !$conversation->hasParticipant($request->user_id)) {
            return response()->json([
                'success' => false,
                'message' => 'Not a participant',
            ], 403);
        }

        MessageReaction::toggle($messageId, (int) $request->user_id, $request->emoji);

        $message->load([
            'sender:id,first_name,last_name,username,profile_photo_path',
            'reactions',
        ]);

        // Firebase notify
        $this->firebaseLiveUpdate->notifyConversationParticipants($id, (int) $request->user_id, 'messages_updated');

        // Broadcast reaction change via WebSocket
        broadcast(new MessageUpdated($message, 'reaction'))->toOthers();

        return response()->json([
            'success' => true,
            'data' => $message,
        ]);
    }

    /**
     * Remove a reaction from a message.
     */
    public function removeReaction(Request $request, int $id, int $messageId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'emoji' => 'required|string|max:32',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $message = Message::where('conversation_id', $id)
            ->where('id', $messageId)
            ->first();

        if (!$message) {
            return response()->json([
                'success' => false,
                'message' => 'Message not found',
            ], 404);
        }

        MessageReaction::where('message_id', $messageId)
            ->where('user_id', $request->user_id)
            ->where('emoji', $request->emoji)
            ->delete();

        $message->load([
            'sender:id,first_name,last_name,username,profile_photo_path',
            'reactions',
        ]);

        return response()->json([
            'success' => true,
            'data' => $message,
        ]);
    }

    /**
     * Toggle mute for a conversation.
     */
    public function toggleMute(Request $request, int $id): JsonResponse
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

        // Accept explicit muted value, or toggle
        if ($request->has('muted')) {
            $participant->update(['is_muted' => (bool) $request->muted]);
        } else {
            $participant->toggleMute();
        }

        $isMuted = $participant->fresh()->is_muted;

        return response()->json([
            'success' => true,
            'message' => $isMuted ? 'Conversation muted' : 'Conversation unmuted',
            'data' => ['is_muted' => $isMuted],
        ]);
    }

    /**
     * Report a conversation.
     */
    public function reportConversation(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'reason' => 'required|string|max:1000',
            'category' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $conversation = Conversation::find($id);
        if (!$conversation) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found',
            ], 404);
        }

        Report::create([
            'reportable_type' => Conversation::class,
            'reportable_id' => $id,
            'user_id' => $request->user_id,
            'reason' => $request->reason,
            'category' => $request->category ?? 'conversation',
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Report submitted',
        ]);
    }

    /**
     * Report a message.
     */
    public function reportMessage(Request $request, int $id, int $messageId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'reason' => 'required|string|max:1000',
            'category' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $message = Message::where('conversation_id', $id)
            ->where('id', $messageId)
            ->first();

        if (!$message) {
            return response()->json([
                'success' => false,
                'message' => 'Message not found',
            ], 404);
        }

        Report::create([
            'reportable_type' => Message::class,
            'reportable_id' => $messageId,
            'user_id' => $request->user_id,
            'reason' => $request->reason,
            'category' => $request->category ?? 'message',
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Report submitted',
        ]);
    }

    /**
     * Get online participants in a conversation.
     */
    public function getOnlineParticipants(Request $request, int $id): JsonResponse
    {
        $conversation = Conversation::find($id);

        if (!$conversation) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found',
            ], 404);
        }

        $participantIds = ConversationParticipant::where('conversation_id', $id)
            ->pluck('user_id')
            ->toArray();

        $onlineIds = UserPresence::getOnlineUserIds($participantIds);

        return response()->json([
            'success' => true,
            'data' => [
                'total_participants' => count($participantIds),
                'online_count' => count($onlineIds),
                'online_user_ids' => $onlineIds,
            ],
        ]);
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

        // Mark all unread messages (sent by others) as read
        Message::where('conversation_id', $id)
            ->where('sender_id', '!=', $request->user_id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

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

            // Firebase: notify other participants that someone left
            $this->firebaseLiveUpdate->notifyConversationParticipants($id, (int) $userId, 'messages_updated');

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
            'data' => ['unread_count' => (int) $count],
        ]);
    }

    /**
     * Format conversation for response.
     */
    private function formatConversation(Conversation $conversation, int $userId): array
    {
        $conversation->load([
            'participants:id,first_name,last_name,username,profile_photo_path',
            'lastMessage:id,conversation_id,sender_id,content,message_type,media_path,media_type,reply_to_id,is_read,read_at,created_at,updated_at',
            'lastMessage.sender:id,first_name,last_name,username,profile_photo_path',
        ]);

        $participant = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $userId)
            ->first();

        $data = $conversation->toArray();

        // Build full participants array with nested user + pivot data
        $participantRecords = ConversationParticipant::where('conversation_id', $conversation->id)
            ->with('user:id,first_name,last_name,username,profile_photo_path')
            ->get();

        $data['participants'] = $participantRecords->map(function ($p) {
            return [
                'id' => $p->id,
                'conversation_id' => $p->conversation_id,
                'user_id' => $p->user_id,
                'is_admin' => $p->is_admin,
                'last_read_at' => $p->last_read_at,
                'unread_count' => $p->unread_count,
                'is_muted' => $p->is_muted,
                'user' => $p->user ? [
                    'id' => $p->user->id,
                    'first_name' => $p->user->first_name,
                    'last_name' => $p->user->last_name,
                    'username' => $p->user->username,
                    'profile_photo_path' => $p->user->profile_photo_path,
                ] : null,
            ];
        })->toArray();

        if ($conversation->isPrivate()) {
            $otherUser = $conversation->getOtherParticipant($userId);
            if ($otherUser) {
                $data['display_name'] = $otherUser->full_name;
                $data['display_photo'] = $otherUser->profile_photo_path;
            }
        } else {
            $data['display_name'] = $conversation->name;
            $data['display_photo'] = $conversation->avatar_path;
        }

        $data['unread_count'] = $participant?->unread_count ?? 0;
        $data['is_muted'] = $participant?->is_muted ?? false;
        $data['is_pinned'] = $participant?->is_pinned ?? false;
        $data['is_favorite'] = $participant?->is_favorite ?? false;
        $data['is_archived'] = $participant?->is_archived ?? false;
        $data['folder'] = $participant?->folder;
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
     * Toggle pin for a conversation.
     */
    public function togglePin(Request $request, int $id): JsonResponse
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

        $participant->togglePin();

        return response()->json([
            'success' => true,
            'message' => $participant->fresh()->is_pinned ? 'Conversation pinned' : 'Conversation unpinned',
            'data' => ['is_pinned' => $participant->fresh()->is_pinned],
        ]);
    }

    /**
     * Toggle favorite for a conversation.
     */
    public function toggleFavorite(Request $request, int $id): JsonResponse
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

        $participant->toggleFavorite();

        return response()->json([
            'success' => true,
            'message' => $participant->fresh()->is_favorite ? 'Added to favorites' : 'Removed from favorites',
            'data' => ['is_favorite' => $participant->fresh()->is_favorite],
        ]);
    }

    /**
     * Toggle archive for a conversation.
     */
    public function toggleArchive(Request $request, int $id): JsonResponse
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

        $participant->toggleArchive();

        return response()->json([
            'success' => true,
            'message' => $participant->fresh()->is_archived ? 'Conversation archived' : 'Conversation unarchived',
            'data' => ['is_archived' => $participant->fresh()->is_archived],
        ]);
    }

    /**
     * Set folder for a conversation.
     */
    public function setFolder(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'folder' => 'nullable|string|max:50',
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

        $participant->setFolder($request->folder);

        return response()->json([
            'success' => true,
            'message' => $request->folder ? "Moved to folder: {$request->folder}" : 'Removed from folder',
            'data' => ['folder' => $participant->fresh()->folder],
        ]);
    }

    /**
     * Add a participant to a group conversation.
     */
    public function addParticipant(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'participant_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $conversation = Conversation::find($id);

        if (!$conversation) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found',
            ], 404);
        }

        if ($conversation->isPrivate()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot add participants to a private conversation',
            ], 400);
        }

        // Check requester is admin
        $requester = ConversationParticipant::where('conversation_id', $id)
            ->where('user_id', $request->user_id)
            ->first();

        if (!$requester || !$requester->is_admin) {
            return response()->json([
                'success' => false,
                'message' => 'Only admins can add participants',
            ], 403);
        }

        // Check if already a participant
        $existing = ConversationParticipant::where('conversation_id', $id)
            ->where('user_id', $request->participant_id)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => true,
                'message' => 'User is already a participant',
            ]);
        }

        ConversationParticipant::create([
            'conversation_id' => $id,
            'user_id' => $request->participant_id,
        ]);

        // Also add to linked group if exists
        if ($conversation->group_id) {
            $group = $conversation->group;
            if ($group && !$group->isMember($request->participant_id)) {
                $group->members()->attach($request->participant_id, ['role' => 'member', 'status' => 'approved']);
            }
        }

        // Firebase notify
        $this->firebaseLiveUpdate->notifyConversationParticipants($id, (int) $request->user_id, 'messages_updated');

        return response()->json([
            'success' => true,
            'message' => 'Participant added',
            'data' => $this->formatConversation($conversation->fresh(), (int) $request->user_id),
        ]);
    }

    /**
     * Remove a participant from a group conversation.
     */
    public function removeParticipant(Request $request, int $id, int $participantUserId): JsonResponse
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

        $conversation = Conversation::find($id);

        if (!$conversation) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found',
            ], 404);
        }

        if ($conversation->isPrivate()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot remove participants from a private conversation',
            ], 400);
        }

        // Check requester is admin
        $requester = ConversationParticipant::where('conversation_id', $id)
            ->where('user_id', $request->user_id)
            ->first();

        if (!$requester || !$requester->is_admin) {
            return response()->json([
                'success' => false,
                'message' => 'Only admins can remove participants',
            ], 403);
        }

        // Cannot remove yourself via this endpoint (use destroy/leave instead)
        if ((int) $request->user_id === $participantUserId) {
            return response()->json([
                'success' => false,
                'message' => 'Use leave endpoint to remove yourself',
            ], 400);
        }

        $participant = ConversationParticipant::where('conversation_id', $id)
            ->where('user_id', $participantUserId)
            ->first();

        if (!$participant) {
            return response()->json([
                'success' => false,
                'message' => 'User is not a participant',
            ], 404);
        }

        $participant->delete();

        // Also remove from linked group if exists
        if ($conversation->group_id) {
            $group = $conversation->group;
            if ($group) {
                $group->members()->detach($participantUserId);
            }
        }

        // Firebase notify
        $this->firebaseLiveUpdate->notifyUser($participantUserId, 'messages_updated', ['conversation_id' => $id]);
        $this->firebaseLiveUpdate->notifyConversationParticipants($id, (int) $request->user_id, 'messages_updated');

        return response()->json([
            'success' => true,
            'message' => 'Participant removed',
        ]);
    }

    /**
     * Promote a participant to admin.
     */
    public function promoteAdmin(Request $request, int $id, int $participantUserId): JsonResponse
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

        $conversation = Conversation::find($id);

        if (!$conversation || $conversation->isPrivate()) {
            return response()->json([
                'success' => false,
                'message' => 'Group conversation not found',
            ], 404);
        }

        // Check requester is admin
        $requester = ConversationParticipant::where('conversation_id', $id)
            ->where('user_id', $request->user_id)
            ->first();

        if (!$requester || !$requester->is_admin) {
            return response()->json([
                'success' => false,
                'message' => 'Only admins can promote participants',
            ], 403);
        }

        $participant = ConversationParticipant::where('conversation_id', $id)
            ->where('user_id', $participantUserId)
            ->first();

        if (!$participant) {
            return response()->json([
                'success' => false,
                'message' => 'User is not a participant',
            ], 404);
        }

        $participant->update(['is_admin' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Participant promoted to admin',
            'data' => ['is_admin' => true],
        ]);
    }

    /**
     * Demote an admin to regular participant.
     */
    public function demoteAdmin(Request $request, int $id, int $participantUserId): JsonResponse
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

        $conversation = Conversation::find($id);

        if (!$conversation || $conversation->isPrivate()) {
            return response()->json([
                'success' => false,
                'message' => 'Group conversation not found',
            ], 404);
        }

        // Check requester is admin
        $requester = ConversationParticipant::where('conversation_id', $id)
            ->where('user_id', $request->user_id)
            ->first();

        if (!$requester || !$requester->is_admin) {
            return response()->json([
                'success' => false,
                'message' => 'Only admins can demote participants',
            ], 403);
        }

        // Cannot demote yourself if you're the only admin
        if ((int) $request->user_id === $participantUserId) {
            $adminCount = ConversationParticipant::where('conversation_id', $id)
                ->where('is_admin', true)
                ->count();

            if ($adminCount <= 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot demote the last admin',
                ], 400);
            }
        }

        $participant = ConversationParticipant::where('conversation_id', $id)
            ->where('user_id', $participantUserId)
            ->first();

        if (!$participant) {
            return response()->json([
                'success' => false,
                'message' => 'User is not a participant',
            ], 404);
        }

        $participant->update(['is_admin' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Admin demoted to participant',
            'data' => ['is_admin' => false],
        ]);
    }

    /**
     * Search messages within a conversation.
     */
    public function searchMessages(Request $request, int $id): JsonResponse
    {
        $userId = $request->query('user_id');
        $query = $request->query('q');
        $perPage = $request->query('per_page', 20);

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User ID is required',
            ], 400);
        }

        if (!$query || strlen($query) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'Search query must be at least 2 characters',
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

        $messages = Message::where('conversation_id', $id)
            ->where('content', 'ilike', "%{$query}%")
            ->with([
                'sender:id,first_name,last_name,username,profile_photo_path',
                'reactions',
            ])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $messages->items(),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ],
        ]);
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

        // Broadcast typing start via WebSocket
        $user = UserProfile::find($userId);
        if ($user) {
            broadcast(new TypingIndicator($id, (int) $userId, $user->first_name ?? '', $user->last_name ?? '', true))->toOthers();
        }

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

        // Broadcast typing stop via WebSocket
        $user = UserProfile::find($userId);
        if ($user) {
            broadcast(new TypingIndicator($id, (int) $userId, $user->first_name ?? '', $user->last_name ?? '', false))->toOthers();
        }

        return response()->json([
            'success' => true,
        ]);
    }

    /**
     * Get typing and recording status of other participants.
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
                    'id' => $p->user_id,
                    'first_name' => $p->user->first_name ?? null,
                    'last_name' => $p->user->last_name ?? null,
                ];
            });

        // Collect recording users from cache
        $participants = ConversationParticipant::where('conversation_id', $id)
            ->where('user_id', '!=', $userId)
            ->with('user:id,first_name,last_name')
            ->get();

        $recordingUsers = $participants->filter(function ($p) use ($id) {
            return Cache::has("recording:{$id}:{$p->user_id}");
        })->map(function ($p) {
            return [
                'id' => $p->user_id,
                'first_name' => $p->user->first_name ?? null,
                'last_name' => $p->user->last_name ?? null,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'typing_users' => $typingUsers,
                'recording_users' => $recordingUsers,
            ],
        ]);
    }

    /**
     * Start recording indicator.
     */
    public function startRecording(Request $request, int $id): JsonResponse
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

        Cache::put("recording:{$id}:{$userId}", true, 30);

        $user = UserProfile::find($userId);
        if ($user) {
            broadcast(new RecordingIndicator($id, (int) $userId, $user->first_name ?? '', $user->last_name ?? '', true))->toOthers();
        }

        return response()->json([
            'success' => true,
        ]);
    }

    /**
     * Stop recording indicator.
     */
    public function stopRecording(Request $request, int $id): JsonResponse
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

        Cache::forget("recording:{$id}:{$userId}");

        $user = UserProfile::find($userId);
        if ($user) {
            broadcast(new RecordingIndicator($id, (int) $userId, $user->first_name ?? '', $user->last_name ?? '', false))->toOthers();
        }

        return response()->json([
            'success' => true,
        ]);
    }
}
