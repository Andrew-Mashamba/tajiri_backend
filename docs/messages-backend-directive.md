# Messages Module — Backend Implementation Directive

This document defines how to implement backend support for the **Messages** module used by the Tajiri Flutter app. It is aligned with [MESSAGES_IMPLEMENTATION_STATUS.md](MESSAGES_IMPLEMENTATION_STATUS.md) and the app's existing API usage.

**Stack:** Laravel (API), PostgreSQL (data), Nginx (HTTP), Firebase (push & optional real-time).

**Important:** The Messages module is **not isolated**. It is embedded with other app modules that share users, storage, auth, **groups**, friends, privacy, events, and files. **Groups** are part of the user profile (Profile → Vikundi tab); Messages should reuse the same groups entity for group chats. Reuse existing backend pieces and keep response shapes consistent with the rest of the API (see [BACKEND.md](BACKEND.md) for story-based contracts).

---

## 0. Integration with existing modules

Messages depends on and shares data with these existing areas. Implement Messages endpoints and tables so they **reuse** the following rather than duplicating them.

| Area | Existing usage in app | Backend reuse |
|------|------------------------|---------------|
| **API base & auth** | All services use `ApiConfig.baseUrl` (includes `/api`) and `ApiConfig.authHeaders(token)`. Many endpoints send `user_id` in body/query; backend may derive user from Bearer token. | Use the same base URL and auth middleware as posts, friends, events, etc. Messages routes live under the same `/api` prefix. |
| **Users** | Conversations, messages, and calls all embed **user objects** for sender, caller, callee, participants. The app uses **PostUser** (posts, comments, message sender), **CallUser** (calls), and **UserProfile** (friends, search) — all expect `id`, `first_name`, `last_name`, `username`, `profile_photo_path`. | Use the **same users table** and a **single user serialization** (e.g. one Laravel API resource or transformer) so that `PostUser.fromJson` / `CallUser.fromJson` / `UserProfile.fromJson` work. Return `profile_photo_path` as a **relative path**; the app builds the full URL with `ApiConfig.storageUrl`. |
| **Storage & media URLs** | Posts, clips, events (cover), profile photos, and **message media** all use the same rule: backend returns a **relative path**; the app prepends `ApiConfig.storageUrl` (e.g. `https://your-domain.com/storage`). See `lib/models/post_models.dart` (`_buildStorageUrl`), `lib/models/message_models.dart` (avatar, media), `lib/models/call_models.dart`. | Store message media on the **same storage disk** (e.g. `storage/app/public` or your existing S3 bucket). Return `media_path` (and `avatar_path`, `profile_photo_path` in user objects) as relative paths. Use the same symlink or CDN base as for posts/clips so URLs are consistent. |
| **Groups** | **Groups are part of the user profile**: Profile → Vikundi tab uses **GroupService** (`lib/services/group_service.dart`): `GET /groups`, `GET /groups/user?user_id=` (user's groups), `POST /groups`, `GET /groups/{id}`, join/leave, group posts. **Group** model (`lib/models/group_models.dart`): id, name, slug, description, cover, privacy, creatorId, membersCount, userRole, isAdmin, etc. Messages group chats and in-group events use the same notion of group (e.g. group chat opens GroupInfoScreen; events use `group_id` = conversation or group id). | **Reuse the existing groups table** (BACKEND.md: groups, join/leave, posts). Link **conversations** (type = group) to **groups**: e.g. `conversations.group_id` FK to `groups.id`, or `groups.conversation_id` FK to `conversations.id`. One group entity then has both (1) profile presence: members, posts, join/leave, and (2) a conversation for chat, group call, and in-group events. When creating a group (from profile), create the linked conversation so the group appears in Messages. Events `group_id` should refer to the same group id. Do not maintain a separate message-only group concept. |
| **Friends** | Messages uses **FriendService**: `GET /friends?user_id=`, `GET /friends/status/{id}`, `GET /users/search?q=`. Used for "Share contact" (pick from friends) and for privacy "who can message" (friends vs everyone). | Messages does **not** define its own friends list. Respect **who_can_message** from privacy settings; use existing `GET /friends` and `GET /users/search` for contact picker and any "message this user" checks. |
| **Privacy** | **PrivacyService**: `GET/PUT users/{id}/privacy-settings`. Model includes `who_can_message`, `last_seen_visibility`, `read_receipts_visibility`, `online_status_visibility`, `profile_photo_visibility`, etc. (`lib/models/privacy_settings_model.dart`). | Extend the **existing** privacy settings endpoint and table (or JSON column) with the same field names. Messages reads these for "who can message me", read receipts, and last seen. Do not create a separate privacy store for Messages. |
| **Events** | **EventService**: `GET /events?group_id=`, `POST /events` with `group_id` when creating from group chat. In-group events screen uses `conversationId` as `groupId` in the API. | Add **group_id** (nullable, FK to conversations or your groups table) to the **existing events table**. Support `GET /events?group_id=` and accept `group_id` in event create. No separate "message events" table. |
| **Report** | Post comments: `POST /comments/{id}/report` (see BACKEND.md / `lib/services/post_service.dart`). Chat screen has "Ripoti" (report) and "Zulia" (block) UI; these currently show dialogs only and do not call an API yet. | Add **block** and **report** endpoints (e.g. `POST /users/block`, `POST /reports`) and tables (`blocked_users`, `reports`) so the app can wire block/report to the backend. Reuse the same **report** pattern (reporter_id, reported_type, reported_id, reason) for conversation/message reports. |
| **Pagination** | All list endpoints expect `meta`: `current_page`, `last_page`, `per_page`, `total` (see `PaginationMeta` in `lib/services/post_service.dart`). | Use the same pagination meta shape for conversation list, message list, and call history so the app's existing parsing works. |
| **Upload** | Message media: **multipart** `POST /conversations/{id}/messages` with field `media` (and `user_id`, `message_type`, etc.). Clips use resumable upload (`POST /uploads/init`, chunk, complete) per BACKEND.md Story 72. | Messages currently use **direct multipart** only. Store the file and set `media_path` on the message. Optionally allow **resumable upload** for very large message files by reusing the same `/uploads/init`, `/uploads/{id}/chunk`, `/uploads/{id}/complete` flow and then attaching the resulting path to the message. |

### Reuse checklist

- **User representation:** One shared shape for API responses that include a user (e.g. `id`, `first_name`, `last_name`, `username`, `profile_photo_path`). Used by posts, comments, messages (sender, participants), calls (caller, callee), friends, and search.
- **Storage:** One storage disk/origin; relative paths for all media and avatars; app builds `storageUrl + path`.
- **Auth:** Same middleware and token handling as in BACKEND.md stories (e.g. Story 39 for conversations).
- **Groups:** One **groups** entity (profile Vikundi + group chat). Link `conversations` (type = group) to `groups` so group chat, group call, and in-group events all use the same group id. Reuse `GET /groups`, `GET /groups/user`, `POST /groups`, `GET /groups/{id}`, join/leave; do not duplicate group data for Messages.
- **Existing endpoints:** Do not duplicate `GET /friends`, `GET /users/search`, `GET/PUT users/{id}/privacy-settings`, `GET /groups` / `GET /groups/user`, or event create/list; extend them or call them from Messages logic where needed.

---

## 1. Overview and Conventions

### 1.1 API Conventions

- **Base URL:** Use the same as the rest of the app. The Flutter app uses `ApiConfig.baseUrl` (e.g. `https://zima-uat.site:8003/api`). All Messages routes are under this base (e.g. `GET /conversations`, `POST /conversations/{id}/messages`). See [BACKEND.md](BACKEND.md) for existing story contracts.
- **Response envelope:** Same as other modules:
  ```json
  { "success": true|false, "data": {...}, "meta": {...}, "message": "..." }
  ```
- **Auth:** Same mechanism as posts, friends, events: Bearer token where applicable; many endpoints also send `user_id` in body/query. Backend may derive identity from token and ignore or validate `user_id`.
- **Pagination:** Query params `page`, `per_page`. Response `meta`: `current_page`, `last_page`, `per_page`, `total` (matches `PaginationMeta` in the app).

### 1.2 Storage and Media

- **Shared storage:** Message media (images, video, audio, documents) should be stored on the **same storage disk** used for posts, clips, and profile photos. The app builds URLs as `ApiConfig.storageUrl + path` (see `lib/models/message_models.dart` for `mediaPath` and avatar). Return **relative paths** only (e.g. `message_media/2024/01/uuid.jpg`).
- **Upload:** The app sends message media via **multipart** to `POST /conversations/{id}/messages` with field name `media`. Support at least 5MB per file; 50MB+ for video. For very large files, you may accept chunked uploads via the existing resumable-upload flow (BACKEND.md Story 72) and then attach the stored path to the message.

---

## 2. Database Schema (PostgreSQL)

Implement via Laravel migrations. Below are the tables and main columns.

### 2.1 Conversations and Participants

```text
conversations
  id (bigint, PK)
  type (string: 'private' | 'group')
  group_id (bigint, nullable, FK groups.id — when type = 'group', reuse profile groups)
  name (string, nullable, for groups; can be denormalized or from groups.name)
  avatar_path (string, nullable; can be from groups.cover_photo_path)
  created_by (bigint, FK users.id)
  last_message_id (bigint, nullable, FK messages.id)
  last_message_at (timestamp, nullable)
  created_at, updated_at

conversation_participants
  id (bigint, PK)
  conversation_id (bigint, FK conversations.id)
  user_id (bigint, FK users.id)
  is_admin (boolean, default false)
  last_read_at (timestamp, nullable)
  unread_count (int, default 0)
  is_muted (boolean, default false)
  created_at, updated_at
  UNIQUE(conversation_id, user_id)
```

- **Private conversation:** One row in `conversations` with `type = 'private'` and exactly two participants. You can resolve "private conversation with user X" by a unique pair of user IDs (e.g. ensure one conversation per pair).
- **Group:** Reuse the **groups** table (profile Vikundi). When `type = 'group'`, set `conversations.group_id` to the group id. The group already has name, cover, creator, members (join/leave); the conversation is that group's chat. When creating a group via `POST /groups` (from profile), create the linked conversation so the group appears in Messages. Multiple participants; at least one `is_admin` (align with group user_role / is_admin).

### 2.2 Messages

```text
messages
  id (bigint, PK)
  conversation_id (bigint, FK conversations.id)
  sender_id (bigint, FK users.id)
  content (text, nullable — used for text, contact JSON, location JSON, sticker/gif payload)
  message_type (string: 'text' | 'image' | 'video' | 'audio' | 'document' | 'location' | 'contact')
  media_path (string, nullable)
  media_type (string, nullable, e.g. MIME)
  reply_to_id (bigint, nullable, FK messages.id)
  forward_message_id (bigint, nullable, FK messages.id — original message when forwarded)
  is_read (boolean, default false) — per-recipient read state can be in a separate table if needed
  read_at (timestamp, nullable)
  created_at, updated_at
  Index: conversation_id, created_at (for listing)
```

- **Contact message:** `message_type = 'contact'`, `content` = JSON e.g. `{"name":"...", "user_id":123}`.
- **Location message:** `message_type = 'location'`, `content` = JSON e.g. `{"lat":..., "lng":...}`.
- **Sticker/GIF:** Can be `message_type = 'text'` with `content` like `[sticker:id]` or a URL; or a dedicated type if you prefer.

### 2.3 Message Reactions

```text
message_reactions
  id (bigint, PK)
  message_id (bigint, FK messages.id)
  user_id (bigint, FK users.id)
  emoji (string, e.g. '👍', '❤️')
  created_at
  UNIQUE(message_id, user_id, emoji)
```

- **Add reaction:** INSERT or toggle (if user already has this emoji on this message, remove it).
- **List reactions:** Return aggregated per message as expected by the app: list of `{ "emoji": "👍", "user_ids": [1, 2] }`.

### 2.4 Typing and Read State

```text
typing_status (or cache/Redis key per conversation)
  conversation_id, user_id, updated_at
  — Optional table; alternatively use Redis/Firebase for short-lived typing.
```

- **Read receipts:** Either `messages.is_read`/`read_at` per message per user, or a `message_reads(message_id, user_id, read_at)` table. App expects conversation-level "mark as read" and message-level read state for "tick" UI.

### 2.5 Calls (1:1)

```text
calls
  id (bigint, PK)
  call_id (string, unique — e.g. UUID for signalling)
  caller_id (bigint, FK users.id)
  callee_id (bigint, FK users.id)
  type (string: 'voice' | 'video')
  status (string: 'pending' | 'ringing' | 'answered' | 'ended' | 'missed' | 'declined')
  started_at (timestamp)
  answered_at (timestamp, nullable)
  ended_at (timestamp, nullable)
  duration (int, nullable, seconds)
  end_reason (string, nullable)
  created_at, updated_at
```

### 2.6 Call History (for Calls tab)

```text
call_logs
  id (bigint, PK)
  user_id (bigint, FK users.id) — the user this log belongs to
  other_user_id (bigint, nullable, FK users.id)
  type (string: 'voice' | 'video')
  direction (string: 'incoming' | 'outgoing')
  status (string: 'answered' | 'missed' | 'declined')
  duration (int, nullable, seconds)
  call_time (timestamp)
  created_at
  Index: user_id, call_time
```

- One row per "participant view" of a call (so each user has their own log row with `direction` and `other_user_id`), or one row per call with `user_id` being the owner of the log and `other_user_id` the other party.

### 2.7 Group Calls

```text
group_calls
  id (bigint, PK)
  call_id (string, unique)
  conversation_id (bigint, FK conversations.id)
  initiated_by (bigint, FK users.id)
  type (string: 'voice' | 'video')
  status (string: 'active' | 'ended')
  started_at (timestamp)
  ended_at (timestamp, nullable)
  max_participants (int, default 32)
  created_at, updated_at

group_call_participants
  id (bigint, PK)
  group_call_id (bigint, FK group_calls.id)
  user_id (bigint, FK users.id)
  status (string: 'invited' | 'joined' | 'left')
  joined_at (timestamp, nullable)
  left_at (timestamp, nullable)
  is_muted (boolean, default false)
  is_video_off (boolean, default false)
  created_at, updated_at
```

- Enforce `max_participants` (e.g. 32) when adding participants.

### 2.8 Block and Report (Privacy & Safety)

```text
blocked_users
  id (bigint, PK)
  user_id (bigint, FK users.id) — who blocked
  blocked_user_id (bigint, FK users.id)
  created_at
  UNIQUE(user_id, blocked_user_id)

reports
  id (bigint, PK)
  reporter_id (bigint, FK users.id)
  reported_type (string: 'user' | 'conversation' | 'message')
  reported_id (bigint)
  reason (text, nullable)
  created_at
```

- When listing conversations or messages, filter out blocked users (e.g. do not show conversations with blocked users; do not deliver messages from blocked to blocker).

### 2.9 Presence and Privacy Settings

- **Presence:** Either a `user_presence` table (`user_id`, `last_seen_at`, `is_online`) updated on activity/heartbeat, or derive from cache/Redis.
- **Privacy settings:** Table or JSON column on `users`: e.g. `last_seen_visibility`, `read_receipts_visibility`, `online_status_visibility`, `profile_photo_visibility`, `about_visibility`, `status_visibility`, `who_can_resend_status` (values: `everyone` | `friends` | `nobody` | `only_me` as per app). App already sends these in `users/{id}/privacy-settings` (GET/PUT).

### 2.10 Favorites, Archive, Folders (Chat Management)

- **Option A (client-only):** App uses SharedPreferences; no backend columns. No change.
- **Option B (sync):** Add to `conversation_participants` or a new table:
  - `is_favorite` (boolean)
  - `is_archived` (boolean)
  - `folder` (string, nullable, e.g. 'Work', 'Friends', 'Personal')
  Then expose in conversation list API (e.g. include these in participant/conversation payload for current user).

### 2.11 Events (In-Group)

- **Existing events table** should have `group_id` (nullable, FK to **groups**.id). Reuse the same **groups** entity as profile and group chat: `group_id` is the group id. The app may send conversation id when opening events from group chat; backend should resolve conversation → group (via `conversations.group_id`) and filter events by that group id. See `lib/services/event_service.dart`: `getEventsByGroup`, `createEvent` with `groupId`.
- App calls:
  - `GET /events?group_id={id}&page=&per_page=&type=upcoming&current_user_id=`
  - Filter events by `group_id` for "Matukio ya kikundi". Create event with `group_id` when created from group chat (multipart form field `group_id` in `POST /events`).

---

## 3. API Contract (Aligned with Flutter App)

Base path: same as `ApiConfig.baseUrl` (includes `/api`). Auth: same as rest of app (Bearer token and/or `user_id` in body/query). The following routes and JSON shapes are implemented by the app in `lib/services/message_service.dart`, `lib/services/call_service.dart`, `lib/services/group_call_service.dart`, and `lib/services/event_service.dart`; backend must match so no client changes are required.

### 3.1 Conversations

| Method | Endpoint | Request | Response |
|--------|----------|--------|----------|
| GET | `/conversations` | Query: `user_id`, `page`, `per_page` | `{ success, data: [ Conversation ], meta }` |
| GET | `/conversations/private/{otherUserId}` | Query: `user_id` | Get or create private conv; `{ success, data: Conversation }` |
| GET | `/conversations/{id}` | Query: `user_id` | `{ success, data: Conversation }` |
| POST | `/conversations` | Body: `user_id`, `name`, `participant_ids[]`, `type: 'group'` | 201 `{ success, data: Conversation }` |
| PUT | `/conversations/{id}/read` | Body: `user_id` | 200 `{ success }` — mark as read for this user |
| DELETE | `/conversations/{id}` | Body: `user_id` | 200 `{ success }` — leave conversation |

**Conversation JSON (per app's `Conversation.fromJson`):**

- `id`, `type`, `name`, `avatar_path`, `created_by`, `last_message_id`, `last_message_at`, `created_at`, `updated_at`
- `last_message` (nested Message or null)
- `participants` (array of ConversationParticipant)
- `display_name`, `display_photo` (computed for 1:1 from other user)
- `unread_count`, `is_muted`, `is_admin` (for current user)

**ConversationParticipant:** `id`, `conversation_id`, `user_id`, `is_admin`, `last_read_at`, `unread_count`, `is_muted`, `user` (nested). The nested `user` must use the **shared user shape**: `id`, `first_name`, `last_name`, `username`, `profile_photo_path` (relative path). Same shape as in posts/comments (`PostUser`) and calls (`CallUser`) so one backend serializer can serve all.

### 3.2 Messages

| Method | Endpoint | Request | Response |
|--------|----------|--------|----------|
| GET | `/conversations/{id}/messages` | Query: `user_id`, `page`, `per_page`, optional `before` | `{ success, data: [ Message ], meta }` |
| POST | `/conversations/{id}/messages` | Body (JSON): `user_id`, `content?`, `message_type`, `reply_to_id?`, `forward_message_id?` — or multipart with `media` file | 201 `{ success, data: Message }` |
| PATCH | `/conversations/{id}/messages/{mid}` | Body: `user_id`, `content` | 200 `{ success, data: Message }` |
| DELETE | `/conversations/{id}/messages/{mid}` | Body: `user_id` | 200 `{ success }` |

**Message JSON (per app's `Message.fromJson` in `lib/models/message_models.dart`):**

- `id`, `conversation_id`, `sender_id`, `content`, `message_type`, `media_path`, `media_type`, `reply_to_id`, `is_read`, `read_at`, `created_at`, `updated_at`
- `sender` (same shared user object: `id`, `first_name`, `last_name`, `username`, `profile_photo_path`)
- `reply_to` (Message or null)
- `reactions`: array of `{ "emoji": "👍", "user_ids": [1,2] }`

**message_type:** `text`, `image`, `video`, `audio`, `document`, `location`, `contact`.

- For **location** and **contact**, `content` is JSON string. No file upload.
- Media upload: multipart, field name `media`; optionally `content` for caption.

### 3.3 Message Reactions

| Method | Endpoint | Request | Response |
|--------|----------|--------|----------|
| POST | `/conversations/{id}/messages/{mid}/reactions` | Body: `user_id`, `emoji` | 200 `{ success, data: Message }` (message with updated reactions) — add or toggle |
| DELETE | `/conversations/{id}/messages/{mid}/reactions` | Body: `user_id`, `emoji` | 200 `{ success, data: Message }` |

### 3.4 Typing

| Method | Endpoint | Request | Response |
|--------|----------|--------|----------|
| POST | `/conversations/{id}/typing/start` | Body: `user_id` | 200 `{ success }` |
| POST | `/conversations/{id}/typing/stop` | Body: `user_id` | 200 `{ success }` |
| GET | `/conversations/{id}/typing` | Query: `user_id` | 200 `{ success, data: { typing_users: [ { id, first_name, last_name } ] } }` |

- Typing can be stored in cache (Redis) with TTL (e.g. 10s); or Firebase for real-time.

### 3.5 Unread Count

| Method | Endpoint | Response |
|--------|----------|----------|
| GET | `/conversations/unread-count` | Query: `user_id` → 200 `{ success, data: { unread_count: number } }` |

### 3.6 Calls (1:1)

| Method | Endpoint | Request | Response |
|--------|----------|--------|----------|
| POST | `/calls/initiate` | Body: `user_id`, `callee_id`, `type` (voice\|video) | 201 `{ success, data: Call }` |
| POST | `/calls/{callId}/answer` | Body: `user_id` | 200 `{ success, data: Call }` |
| POST | `/calls/{callId}/decline` | Body: `user_id` | 200 `{ success, data: Call }` |
| POST | `/calls/{callId}/end` | Body: `user_id` | 200 `{ success, data: Call }` |
| GET | `/calls/{callId}/status` | Query: `user_id` | 200 `{ success, data: Call }` |
| GET | `/calls/history` | Query: `user_id`, `page`, `per_page`, optional `type`, `direction` | 200 `{ success, data: [ CallLog ], meta }` |

**Call:** `id`, `call_id`, `caller_id`, `callee_id`, `type`, `status`, `started_at`, `answered_at`, `ended_at`, `duration`, `end_reason`, `caller`, `callee` (nested user objects with `id`, `first_name`, `last_name`, `username`, `profile_photo_path`).

**CallLog:** `id`, `user_id`, `other_user_id`, `type`, `direction`, `status`, `duration`, `call_time`, `other_user`.

### 3.7 Group Calls

| Method | Endpoint | Request | Response |
|--------|----------|--------|----------|
| POST | `/calls/group` | Body: `conversation_id`, `user_id` | 200/201 `{ success, call_id, room_token?, participants: [ { user_id, display_name?, avatar_url?, is_muted?, video_enabled? } ] }` |
| POST | `/calls/group/leave` | Body: `call_id`, `user_id` | 200 `{ success }` |
| PATCH | `/calls/group/state` | Body: `call_id`, `user_id`, `muted?`, `video_enabled?` | 200 `{ success }` |

- **Add participant:** Add an endpoint e.g. `POST /calls/group/invite` with `call_id`, `user_id` (inviter), `invitee_user_id`; create participant row with status `invited` and send push/Firebase to invitee. Enforce `max_participants` (e.g. 32).

### 3.8 Events (In-Group)

| Method | Endpoint | Request | Response |
|--------|----------|--------|----------|
| GET | `/events` | Query: `page`, `per_page`, `type`, `group_id`, `current_user_id` | 200 `{ success, data: [ Event ] }` — filter by `group_id` when provided |
| POST | `/events` | Body (or multipart): include `group_id` when creating from group | 201 `{ success, data: Event }` |

- Existing event create/respond/attendees APIs remain; ensure `group_id` is accepted and stored, and list endpoint filters by `group_id`.

### 3.9 Block and Report

- **Block:** `POST /users/block` or `POST /blocked-users` with `user_id`, `blocked_user_id`. Store in `blocked_users`. Apply in conversations and message delivery.
- **Report:** `POST /reports` with `reporter_id`, `reported_type` (user/conversation/message), `reported_id`, `reason`. Store in `reports` for moderation.

(Exact paths can match your existing Laravel route design.)

### 3.10 Privacy / Presence

- **GET/PUT** `users/{id}/privacy-settings`: Already expected by the app. Persist: `profile_visibility`, `who_can_message`, `who_can_see_posts`, `last_seen_visibility`, and extended: `read_receipts_visibility`, `online_status_visibility`, `profile_photo_visibility`, `about_visibility`, `status_visibility`, `who_can_resend_status`.
- **Presence:** Optional endpoint e.g. `GET /conversations/{id}/online` returning count or list of online user IDs (from `user_presence` or cache), for "X wanachama • Y online" in group header.

---

## 4. Firebase Usage

- **FCM (Firebase Cloud Messaging):**
  - Store FCM tokens per user/device (e.g. `user_id`, `device_id`, `fcm_token`).
  - Send push notifications for: new message, incoming call, group call invite, optional "message reminder" if you implement server-side reminders.
  - Use high-priority for calls so the device can wake for ringing.

- **Firestore (optional):**
  - Use for real-time typing and presence to avoid polling: e.g. `conversations/{id}/typing/{userId}`, `users/{id}/presence`.
  - App can keep using REST for CRUD and use Firestore listeners only for typing/presence if you add the client logic.

- **Auth:** Keep using Laravel for auth; use Firebase only for push and optional real-time channels.

---

## 5. Nginx and Laravel

- **Upload size:** Set `client_max_body_size` (e.g. 50M) for message media uploads.
- **Timeouts:** Long enough for large file uploads (e.g. 300s).
- **Laravel:** Use queues for post-upload processing (e.g. thumbnails, virus scan if needed). Store files on disk or S3; return `media_path` relative to your storage URL.

---

## 6. Implementation Phases

**Phase 1 — Core (required for current app)**
- Conversations (create, get, list, private resolve).
- Messages (send, list, edit, delete, forward; media upload with path).
- Message reactions (add/remove, return in message payload).
- Mark read, unread count, leave conversation.
- Typing (start/stop/status) — can be in-memory/cache first.
- Basic call tables and 1:1 call APIs (initiate, answer, decline, end, status).
- Call history API.
- Group calls (start/join, leave, state: mute/video).
- Blocked users and reports (store + apply to conversations/messages).
- Events: `group_id` on events and `GET /events?group_id=` support.

**Phase 2 — Sync and real-time**
- FCM: store tokens, send push on new message and calls.
- Optional: Firestore (or WebSockets) for typing and presence.
- Presence table/cache and "online" count for groups.

**Phase 3 — Extras**
- Favorites/archive/folders in DB if you want them server-synced.
- Two-step verification and strict account protection if not already present.
- Scheduled calls / guest links if product requires.
- Group polls in chat (new message type or linked poll resource).
- Speaker spotlight and in-call emoji for group calls (signalling/state in DB + real-time channel).

---

## 7. Feature-to-Backend Mapping (from MESSAGES_IMPLEMENTATION_STATUS)

| Feature | Backend requirement |
|--------|----------------------|
| One-to-one & group chat | Conversations + participants + messages. **Group chats** reuse the **groups** table (profile Vikundi): link conversation to group via `conversations.group_id`; one group = profile presence + conversation (chat, call, events). |
| Edit/delete/forward/drafts | Edit/delete/forward via APIs; drafts are client-only unless you add draft storage. |
| Reply (quote) | `reply_to_id` on messages. |
| Voice/video messages | `message_type` audio/video, `media` upload, `media_path` in response. |
| Photos, documents, contact, location | message_type + content (JSON for contact/location) or media. |
| Stickers/GIFs | text or dedicated type; content holds payload. |
| Message reactions | `message_reactions` table + add/remove reaction APIs; include `reactions` in Message. |
| Typing | Typing start/stop/status APIs (or Firestore). |
| Read receipts | Mark read API; optionally per-message read state. |
| Unread count | Unread-count API. |
| 1:1 calls | Calls table + initiate/answer/decline/end/status + call history. |
| Group calls | group_calls + group_call_participants; start/join, leave, state; enforce max 32. |
| Add participant in call | Invite API + push to invitee; enforce max_participants. |
| Block and report | blocked_users, reports; filter conversations and messages. |
| Presence / "online" | user_presence or cache; optional endpoint for group online count. |
| Privacy settings | Extended privacy fields (read_receipts, online_status, etc.) in GET/PUT. |
| Favorites/archive/folders | Optional DB columns or table for sync. |
| Search conversations | List conversations with filter by name/participant (existing list + search param). |
| In-group events | events.group_id + GET /events?group_id= + create with group_id. |

---

## 8. Summary

- **Messages is not isolated:** Reuse the same **users** table and user serialization, **storage** disk and URL convention, **auth** middleware, **groups** (profile Vikundi + group chat), **friends** and **users/search** APIs, **privacy** settings endpoint, and **events** API (with `group_id` = group id) as the rest of the app. See **Section 0** and [BACKEND.md](BACKEND.md) for existing contracts.
- **Laravel + PostgreSQL:** Add conversations, messages, reactions, participants, calls, call_logs, group_calls, blocked_users, reports; typing (or cache), presence; extend privacy and events as above.
- **Nginx:** Increase upload size and timeouts for media (same as for posts/clips).
- **Firebase:** FCM for push (messages, calls); optionally Firestore for typing/presence.
- Follow the API contract and JSON shapes in **Section 3** so the existing Flutter app (e.g. `lib/services/message_service.dart`, `lib/services/call_service.dart`, `lib/services/group_call_service.dart`, `lib/services/event_service.dart`) works without changes.

This directive, together with [BACKEND.md](BACKEND.md) and [MESSAGES_IMPLEMENTATION_STATUS.md](MESSAGES_IMPLEMENTATION_STATUS.md), is sufficient for a Laravel/PostgreSQL team to implement the Messages backend as an integrated part of the existing API.
