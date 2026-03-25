# Messages Module — Implementation Plan

## Current State Assessment

After thorough codebase analysis, **~70% of the Messages core is already implemented**:

### Already Done
- **Conversations**: Model, migration, controller (CRUD, list, private resolve, show)
- **Messages**: Model, migration, controller (send with media, list with pagination, reply_to)
- **Conversation Participants**: Model, migration (admin, unread_count, is_muted)
- **Typing Indicators**: Migration + controller endpoints (start/stop/get via participant table)
- **Mark as Read**: Controller endpoint + participant model method
- **Unread Count**: Global unread-count endpoint
- **Leave Conversation**: Controller endpoint (delete participant, cleanup)
- **1:1 Calls**: Model, migration, controller (initiate, answer, decline, end, missed, incoming, history)
- **Group Calls**: Model, migration, controller (start, join, leave, decline, end, mute, video toggle)
- **Call Logs**: Model, migration, controller (history by user)
- **Privacy Settings**: 4 fields on user_profiles (profile_visibility, who_can_message, who_can_see_posts, last_seen_visibility) + GET/PUT endpoints
- **Reports**: Polymorphic model + migration (can report any model type)
- **Blocking**: Via Friendship model (status='blocked') — works but is friendship-based
- **Firebase**: FirebaseLiveUpdateService with `notifyConversationParticipants()`
- **Events**: Model with `group_id` FK already exists
- **Groups**: Full model with members, admins, roles, posts, events

### Gaps to Implement

| # | Gap | Priority | Effort |
|---|-----|----------|--------|
| 1 | **Message editing** (PATCH endpoint) | P1 | Small |
| 2 | **Message deletion** (DELETE endpoint) | P1 | Small |
| 3 | **Message forwarding** (`forward_message_id` column + logic) | P1 | Small |
| 4 | **Message reactions** (table + model + endpoints) | P1 | Medium |
| 5 | **Conversations ↔ Groups link** (`group_id` FK on conversations) | P1 | Medium |
| 6 | **Blocked users table** (dedicated, not just friendship-based) | P1 | Small |
| 7 | **Block/unblock API endpoints** | P1 | Small |
| 8 | **Block enforcement** in conversations/messages | P1 | Medium |
| 9 | **Extended privacy settings** (read_receipts_visibility, online_status_visibility, etc.) | P2 | Small |
| 10 | **User presence** (last_seen tracking, online status) | P2 | Medium |
| 11 | **Conversation search** (filter by name/participant) | P2 | Small |
| 12 | **Group conversation ↔ Group auto-linking** (create conversation when group created) | P1 | Medium |
| 13 | **FCM token storage + push notifications** | P2 | Medium |
| 14 | **Conversation mute toggle endpoint** | P1 | Small |
| 15 | **Group chat admin management** (add/remove participants, promote admin) | P2 | Medium |
| 16 | **Call logs creation** (auto-create when call ends) | P1 | Small |
| 17 | **who_can_message enforcement** on private conversation creation | P1 | Small |
| 18 | **Conversation participants list in response** (full shape with nested user) | P1 | Small |

---

## Implementation Plan (Ordered Steps)

### Phase 1A — Database Migrations (5 migrations)

**Step 1: Add `forward_message_id` and `group_id` columns**
- File: `database/migrations/2026_02_14_140000_add_messaging_enhancements.php`
- Add `forward_message_id` (nullable FK to messages) on `messages` table
- Add `group_id` (nullable FK to groups) on `conversations` table
- Add `edited_at` (nullable timestamp) on `messages` table

**Step 2: Create `message_reactions` table**
- File: `database/migrations/2026_02_14_140001_create_message_reactions_table.php`
- Columns: id, message_id (FK), user_id (FK), emoji (string), created_at
- Unique constraint: (message_id, user_id, emoji)

**Step 3: Create `blocked_users` table**
- File: `database/migrations/2026_02_14_140002_create_blocked_users_table.php`
- Columns: id, user_id (FK), blocked_user_id (FK), created_at
- Unique constraint: (user_id, blocked_user_id)

**Step 4: Extend privacy settings**
- File: `database/migrations/2026_02_14_140003_extend_privacy_settings.php`
- Add to `user_profiles`: `read_receipts_visibility`, `online_status_visibility`, `profile_photo_visibility`, `about_visibility`, `status_visibility` (all default 'everyone')

**Step 5: Create `user_presence` table + `fcm_tokens` table**
- File: `database/migrations/2026_02_14_140004_create_presence_and_fcm_tables.php`
- `user_presence`: user_id (unique FK), last_seen_at, is_online, updated_at
- `fcm_tokens`: id, user_id (FK), device_id, fcm_token, platform, created_at, updated_at
- Unique constraint on fcm_tokens: (user_id, device_id)

### Phase 1B — Models (4 new models)

**Step 6: MessageReaction model**
- File: `app/Models/MessageReaction.php`
- Relationships: message(), user()

**Step 7: BlockedUser model**
- File: `app/Models/BlockedUser.php`
- Relationships: user(), blockedUser()
- Static helpers: `isBlocked($userId, $blockedUserId)`, `getBlockedIds($userId)`

**Step 8: UserPresence model**
- File: `app/Models/UserPresence.php`
- Methods: `updatePresence()`, `goOffline()`

**Step 9: FcmToken model**
- File: `app/Models/FcmToken.php`
- Relationships: user()

### Phase 1C — Update Existing Models (4 models)

**Step 10: Update Message model**
- Add `forward_message_id` to `$fillable`
- Add `edited_at` to `$fillable` and `$casts`
- Add `forwardedFrom()` relationship
- Add `reactions()` hasMany relationship
- Add `getReactionsGroupedAttribute()` — aggregated reactions for API response

**Step 11: Update Conversation model**
- Add `group_id` to `$fillable`
- Add `group()` belongsTo relationship
- Update `getOrCreatePrivate()` to check blocked users

**Step 12: Update Group model**
- Add `conversation()` hasOne relationship
- Add helper `getOrCreateConversation()` to auto-create linked conversation

**Step 13: Update UserProfile model**
- Add new privacy fields to `$fillable`
- Add `presence()` hasOne relationship
- Add `blockedUsers()` and `blockedByUsers()` relationships
- Add `isBlockedBy($userId)` helper

### Phase 1D — Controller Updates (ConversationController enhancements)

**Step 14: Add message edit endpoint**
- `PATCH /conversations/{id}/messages/{mid}`
- Validate sender owns message
- Update content + set edited_at
- Return updated message with reactions

**Step 15: Add message delete endpoint**
- `DELETE /conversations/{id}/messages/{mid}`
- Validate sender owns message
- Soft-delete the message
- Firebase notify participants

**Step 16: Add forward support to sendMessage**
- Accept `forward_message_id` in sendMessage
- Copy original message content/type/media when forwarding
- Store `forward_message_id` reference

**Step 17: Add message reactions endpoints**
- `POST /conversations/{id}/messages/{mid}/reactions` — add/toggle reaction
- `DELETE /conversations/{id}/messages/{mid}/reactions` — remove reaction
- Return message with updated grouped reactions

**Step 18: Add conversation mute toggle**
- `POST /conversations/{id}/mute` — toggle mute for user
- Return updated mute status

**Step 19: Add conversation search**
- Update `GET /conversations` to accept `search` query param
- Filter by conversation name or participant name

**Step 20: Ensure full Conversation JSON response shape**
- Update `formatConversation()` to include full `participants` array with nested user objects
- Include `is_admin` for current user
- Include `last_message` with sender

### Phase 1E — Block & Report Endpoints

**Step 21: Create BlockController**
- File: `app/Http/Controllers/Api/BlockController.php`
- `POST /users/block` — block a user (create blocked_users row)
- `POST /users/unblock` — unblock a user
- `GET /users/blocked` — list blocked users

**Step 22: Enforce blocking in ConversationController**
- In `getPrivate()`: check blocked before creating conversation
- In `sendMessage()`: check blocked before allowing message
- In `index()`: filter out conversations with blocked users (or mark them)

**Step 23: Add report endpoint for messages/conversations**
- `POST /conversations/{id}/report` — report a conversation
- `POST /conversations/{id}/messages/{mid}/report` — report a message
- Uses existing polymorphic Report model

### Phase 1F — Group ↔ Conversation Linking

**Step 24: Link groups to conversations**
- When `POST /groups` creates a group, auto-create a linked conversation
- Set `conversations.group_id = groups.id`
- Sync group members to conversation participants
- When user joins/leaves group, add/remove from conversation

**Step 25: Group conversation API support**
- Ensure `GET /conversations` shows group conversations (with group name/avatar)
- Add `GET /conversations?group_id={id}` filter

### Phase 1G — Call Log Auto-Creation

**Step 26: Auto-create call logs when calls end**
- In CallController `end()`, `decline()`, `markMissed()`: create CallLog entries
- Create two rows (one per participant) with correct direction

### Phase 1H — Privacy Enforcement

**Step 27: who_can_message enforcement**
- In `getPrivate()` and `store()`: check target user's `who_can_message` setting
- 'everyone': allow all
- 'friends': check Friendship::areFriends()
- 'nobody': deny

**Step 28: Extend privacy settings endpoint**
- Update `getPrivacySettings()` and `updatePrivacySettings()` in UserProfileController
- Add new fields: read_receipts_visibility, online_status_visibility, profile_photo_visibility, about_visibility, status_visibility

### Phase 1I — Routes

**Step 29: Register all new routes in `routes/api.php`**
```
// Message actions
PATCH /conversations/{id}/messages/{mid}     → editMessage
DELETE /conversations/{id}/messages/{mid}    → deleteMessage

// Reactions
POST   /conversations/{id}/messages/{mid}/reactions   → addReaction
DELETE /conversations/{id}/messages/{mid}/reactions   → removeReaction

// Conversation extras
POST /conversations/{id}/mute               → toggleMute

// Block
POST /users/block                            → block
POST /users/unblock                          → unblock
GET  /users/blocked                          → listBlocked

// Reports (messages)
POST /conversations/{id}/report              → reportConversation
POST /conversations/{id}/messages/{mid}/report → reportMessage

// Presence
POST /users/presence/heartbeat               → updatePresence
GET  /conversations/{id}/online              → getOnlineParticipants

// FCM
POST /users/fcm-token                        → storeFcmToken
DELETE /users/fcm-token                      → removeFcmToken
```

### Phase 2 — Real-Time & Push (after Phase 1)

**Step 30: FCM push notifications**
- Store FCM tokens via endpoint
- Send push on new message (queue job)
- Send high-priority push on incoming call

**Step 31: Presence tracking**
- Heartbeat endpoint updates `user_presence`
- Middleware or schedule to mark stale users offline
- `GET /conversations/{id}/online` returns online participant count

**Step 32: WebSocket/Reverb broadcasting for messages**
- Broadcast new message events on conversation channel
- Broadcast typing events in real-time
- Broadcast call events (ringing, answered, ended)

### Phase 3 — Extras (future)

- Favorites/archive/folders (DB columns on conversation_participants)
- Conversation pinning
- Message search within conversations
- Group polls in chat
- Disappearing messages
- Voice message transcription

---

## Files to Create

| File | Description |
|------|-------------|
| `database/migrations/2026_02_14_140000_add_messaging_enhancements.php` | forward_message_id, group_id, edited_at |
| `database/migrations/2026_02_14_140001_create_message_reactions_table.php` | Reactions table |
| `database/migrations/2026_02_14_140002_create_blocked_users_table.php` | Blocked users table |
| `database/migrations/2026_02_14_140003_extend_privacy_settings.php` | Extended privacy columns |
| `database/migrations/2026_02_14_140004_create_presence_and_fcm_tables.php` | Presence + FCM tokens |
| `app/Models/MessageReaction.php` | Reaction model |
| `app/Models/BlockedUser.php` | Block model |
| `app/Models/UserPresence.php` | Presence model |
| `app/Models/FcmToken.php` | FCM token model |
| `app/Http/Controllers/Api/BlockController.php` | Block/unblock endpoints |

## Files to Modify

| File | Changes |
|------|---------|
| `app/Models/Message.php` | Add forward, edited_at, reactions |
| `app/Models/Conversation.php` | Add group_id, group() relationship |
| `app/Models/Group.php` | Add conversation() relationship |
| `app/Models/UserProfile.php` | Add presence, blocked relationships, new privacy fields |
| `app/Http/Controllers/Api/ConversationController.php` | Add edit, delete, forward, reactions, mute, search, block enforcement |
| `app/Http/Controllers/Api/CallController.php` | Auto-create call logs |
| `app/Http/Controllers/Api/UserProfileController.php` | Extend privacy settings |
| `routes/api.php` | Register all new routes |

## Estimated Scope

- **10 new files** (5 migrations, 4 models, 1 controller)
- **8 modified files** (4 models, 3 controllers, 1 routes)
- **~20 new API endpoints/actions**
- Phase 1 (core): All steps 1–29
- Phase 2 (real-time): Steps 30–32
- Phase 3 (extras): Future iteration
