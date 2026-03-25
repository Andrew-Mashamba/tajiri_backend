# Messages Module — API Reference

> Base URL: `https://zima-uat.site:8003/api`
> All endpoints return JSON with the standard shape: `{success, data, message, errors, meta}`

---

## Table of Contents

1. [Conversations](#1-conversations)
2. [Messages](#2-messages)
3. [Message Reactions](#3-message-reactions)
4. [Typing Indicators](#4-typing-indicators)
5. [Conversation State (Pin/Favorite/Archive/Folder)](#5-conversation-state)
6. [Group Participant Management](#6-group-participant-management)
7. [Reports](#7-reports)
8. [Online Status](#8-online-status)
9. [Calls (1-on-1)](#9-calls-1-on-1)
10. [Group Calls](#10-group-calls)
11. [Block/Unblock](#11-blockunblock)
12. [Presence & FCM Tokens](#12-presence--fcm-tokens)
13. [WebSocket Events](#13-websocket-events)

---

## 1. Conversations

### 1.1 List Conversations

```
GET /conversations?user_id={userId}
```

**Query Parameters:**

| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | The authenticated user's ID |
| `page` | int | No | Page number (default: 1) |
| `per_page` | int | No | Items per page (default: 20) |
| `search` | string | No | Search by conversation name, participant name, or username |
| `group_id` | int | No | Filter by linked group ID |
| `filter` | string | No | One of: `pinned`, `favorites`, `archived`, `unread` |
| `folder` | string | No | Filter by custom folder name |

**Success Response (200):**

```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "type": "private",
      "name": null,
      "avatar_path": null,
      "group_id": null,
      "last_message_at": "2026-02-14T10:30:00.000000Z",
      "display_name": "John Doe",
      "display_photo": "photos/john.jpg",
      "unread_count": 3,
      "is_muted": false,
      "is_pinned": true,
      "is_favorite": false,
      "is_archived": false,
      "folder": null,
      "is_admin": false,
      "last_message": {
        "id": 42,
        "conversation_id": 1,
        "sender_id": 5,
        "content": "Habari!",
        "message_type": "text",
        "created_at": "2026-02-14T10:30:00.000000Z",
        "sender": {
          "id": 5,
          "first_name": "John",
          "last_name": "Doe"
        }
      },
      "participants": [
        {
          "id": 5,
          "first_name": "John",
          "last_name": "Doe",
          "username": "johndoe",
          "profile_photo_path": "photos/john.jpg"
        }
      ]
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 20,
    "total": 45
  }
}
```

**Notes:**
- Archived conversations are excluded by default. Use `filter=archived` to see them.
- Pinned conversations always appear first.
- Conversations with blocked users (private chats) are automatically excluded.

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 400 | `user_id` not provided |

---

### 1.2 Create Conversation (Group or Private)

```
POST /conversations
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | Creator's user ID |
| `participant_ids` | int[] | Yes | Array of user IDs to add (min 1) |
| `name` | string | Required for group | Group name (max 100 chars) |
| `type` | string | No | `private` or `group` (default: `group`) |

**Success Response — Private (200):**

```json
{
  "success": true,
  "message": "Conversation ready",
  "data": { /* conversation object (see 1.4 format) */ }
}
```

**Success Response — Group (201):**

```json
{
  "success": true,
  "message": "Group created successfully",
  "data": { /* conversation object (see 1.4 format) */ }
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 403 | Blocked by the other user (private) |
| 403 | Other user's privacy settings don't allow messages from you |
| 422 | Validation error (missing fields, invalid IDs) |
| 500 | Server error during creation |

---

### 1.3 Get or Create Private Conversation

```
GET /conversations/private/{otherUserId}?user_id={userId}
```

**Query Parameters:**

| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | The authenticated user's ID |

**Path Parameters:**

| Param | Type | Description |
|-------|------|-------------|
| `otherUserId` | int | The other user's ID |

**Success Response (200):**

```json
{
  "success": true,
  "data": { /* conversation object (see 1.4 format) */ }
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 403 | Blocked by the other user |
| 403 | Privacy settings restrict messages |
| 404 | Other user not found |
| 422 | Validation error |

---

### 1.4 Get Conversation Details

```
GET /conversations/{id}?user_id={userId}
```

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "id": 1,
    "type": "group",
    "name": "Developers TZ",
    "avatar_path": null,
    "group_id": null,
    "created_by": 1,
    "last_message_at": "2026-02-14T10:30:00.000000Z",
    "display_name": "Developers TZ",
    "display_photo": null,
    "unread_count": 0,
    "is_muted": false,
    "is_pinned": false,
    "is_favorite": true,
    "is_archived": false,
    "folder": null,
    "is_admin": true,
    "last_message": { /* message object */ },
    "participants": [
      {
        "id": 10,
        "conversation_id": 1,
        "user_id": 5,
        "is_admin": true,
        "last_read_at": "2026-02-14T10:30:00.000000Z",
        "unread_count": 0,
        "is_muted": false,
        "user": {
          "id": 5,
          "first_name": "John",
          "last_name": "Doe",
          "username": "johndoe",
          "profile_photo_path": "photos/john.jpg"
        }
      }
    ]
  }
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 400 | `user_id` not provided |
| 403 | Not a participant |
| 404 | Conversation not found |

---

### 1.5 Delete / Leave Conversation

```
DELETE /conversations/{id}
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | The user leaving |

**Success Response (200):**

```json
{
  "success": true,
  "message": "Left conversation"
}
```

**Notes:**
- For private chats: removes the user's participation record.
- For groups: removes the user. If no participants remain, the conversation and all its messages/media are deleted.

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 403 | Not a participant |
| 404 | Conversation not found |
| 422 | Validation error |
| 500 | Server error |

---

### 1.6 Get Unread Count (All Conversations)

```
GET /conversations/unread-count?user_id={userId}
```

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "unread_count": 12
  }
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 400 | `user_id` not provided |

---

### 1.7 Mark Conversation as Read

```
PUT /conversations/{id}/read
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | The user marking as read |

**Success Response (200):**

```json
{
  "success": true,
  "message": "Marked as read"
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 403 | Not a participant |
| 422 | Validation error |

---

### 1.8 Toggle Mute

```
POST /conversations/{id}/mute
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | The user toggling mute |

**Success Response (200):**

```json
{
  "success": true,
  "message": "Conversation muted",
  "data": { "is_muted": true }
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 403 | Not a participant |
| 422 | Validation error |

---

## 2. Messages

### 2.1 Get Messages

```
GET /conversations/{id}/messages?user_id={userId}
```

**Query Parameters:**

| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | The authenticated user's ID |
| `page` | int | No | Page number (default: 1) |
| `per_page` | int | No | Items per page (default: 50) |
| `before` | int | No | Message ID cursor — returns messages older than this ID |

**Success Response (200):**

```json
{
  "success": true,
  "data": [
    {
      "id": 100,
      "conversation_id": 1,
      "sender_id": 5,
      "content": "Habari za leo?",
      "message_type": "text",
      "media_path": null,
      "media_type": null,
      "reply_to_id": null,
      "forward_message_id": null,
      "edited_at": null,
      "created_at": "2026-02-14T10:30:00.000000Z",
      "updated_at": "2026-02-14T10:30:00.000000Z",
      "sender": {
        "id": 5,
        "first_name": "John",
        "last_name": "Doe",
        "username": "johndoe",
        "profile_photo_path": "photos/john.jpg"
      },
      "reply_to": null,
      "reactions": [
        {
          "id": 1,
          "message_id": 100,
          "user_id": 3,
          "emoji": "❤️",
          "created_at": "2026-02-14T10:31:00.000000Z"
        }
      ]
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 50,
    "total": 230
  }
}
```

**Notes:**
- Messages are returned in chronological order (oldest first within the page).
- Opening messages automatically marks the conversation as read for the requesting user.
- When `reply_to` is present, it includes `{id, conversation_id, sender_id, content, message_type, sender: {id, first_name, last_name}}`.

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 400 | `user_id` not provided |
| 403 | Not a participant |
| 404 | Conversation not found |

---

### 2.2 Send Message

```
POST /conversations/{id}/messages
```

**Request Body (multipart/form-data or JSON):**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | Sender's user ID |
| `content` | string | Required if no `media` | Text content (max 5000 chars) |
| `message_type` | string | No | `text`, `image`, `video`, `audio`, `document`, `location`, `contact` (auto-detected from media if omitted) |
| `media` | file | No | Attachment file (max 50 MB) |
| `reply_to_id` | int | No | Message ID being replied to |
| `forward_message_id` | int | No | Message ID being forwarded |

**Success Response (201):**

```json
{
  "success": true,
  "message": "Message sent",
  "data": {
    "id": 101,
    "conversation_id": 1,
    "sender_id": 5,
    "content": "Check this out!",
    "message_type": "image",
    "media_path": "messages/1/abc123.jpg",
    "media_type": "image/jpeg",
    "reply_to_id": null,
    "forward_message_id": null,
    "edited_at": null,
    "created_at": "2026-02-14T10:35:00.000000Z",
    "sender": { /* user object */ },
    "reply_to": null,
    "reactions": []
  }
}
```

**Side Effects:**
- Broadcasts `new_message` WebSocket event to conversation channel
- Dispatches FCM push notification to all participants (except sender, respects mute)
- Increments `unread_count` for all other participants
- Updates `last_message_at` on the conversation
- Triggers Firebase Firestore `messages_updated` for other participants

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 403 | Not a participant |
| 403 | Blocked by other user (private chats) |
| 404 | Conversation not found |
| 422 | Validation error (empty content and no media, invalid reply_to_id, etc.) |
| 500 | Server error |

---

### 2.3 Edit Message

```
PATCH /conversations/{id}/messages/{messageId}
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | Must be the original sender |
| `content` | string | Yes | Updated text content (max 5000 chars) |

**Success Response (200):**

```json
{
  "success": true,
  "message": "Message updated",
  "data": {
    "id": 101,
    "content": "Updated text",
    "edited_at": "2026-02-14T10:40:00.000000Z",
    "sender": { /* user object */ },
    "reactions": [ /* ... */ ]
  }
}
```

**Side Effects:**
- Broadcasts `message_updated` WebSocket event with `update_type: "edited"`
- Triggers Firebase Firestore `messages_updated`

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 403 | Not the original sender |
| 404 | Message not found |
| 422 | Validation error |

---

### 2.4 Delete Message

```
DELETE /conversations/{id}/messages/{messageId}
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | Must be the original sender |

**Success Response (200):**

```json
{
  "success": true,
  "message": "Message deleted"
}
```

**Side Effects:**
- Broadcasts `message_deleted` WebSocket event
- Deletes associated media file from storage
- Triggers Firebase Firestore `messages_updated`
- Soft-deletes the message record

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 403 | Not the original sender |
| 404 | Message not found |
| 422 | Validation error |

---

### 2.5 Search Messages in Conversation

```
GET /conversations/{id}/messages/search?user_id={userId}&q={query}
```

**Query Parameters:**

| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | The authenticated user's ID |
| `q` | string | Yes | Search query (min 2 characters) |
| `per_page` | int | No | Items per page (default: 20) |

**Success Response (200):**

```json
{
  "success": true,
  "data": [ /* array of message objects */ ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 3
  }
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 400 | `user_id` not provided |
| 400 | `q` missing or less than 2 characters |
| 403 | Not a participant |
| 404 | Conversation not found |

---

## 3. Message Reactions

### 3.1 Add / Toggle Reaction

```
POST /conversations/{id}/messages/{messageId}/reactions
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | Reactor's user ID |
| `emoji` | string | Yes | Emoji string (max 32 chars) |

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "id": 101,
    "content": "Hello!",
    "sender": { /* ... */ },
    "reactions": [
      { "id": 1, "message_id": 101, "user_id": 5, "emoji": "❤️", "created_at": "..." }
    ]
  }
}
```

**Notes:** If the user already has the same emoji on this message, it is removed (toggle). If the user has a different emoji, the old one is replaced.

**Side Effects:**
- Broadcasts `message_updated` WebSocket event with `update_type: "reaction"`
- Triggers Firebase Firestore `messages_updated`

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 403 | Not a participant in the conversation |
| 404 | Message not found |
| 422 | Validation error |

---

### 3.2 Remove Reaction

```
DELETE /conversations/{id}/messages/{messageId}/reactions
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | The user removing their reaction |
| `emoji` | string | Yes | Emoji to remove |

**Success Response (200):**

```json
{
  "success": true,
  "data": { /* message object with updated reactions */ }
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 404 | Message not found |
| 422 | Validation error |

---

## 4. Typing Indicators

### 4.1 Start Typing

```
POST /conversations/{id}/typing/start
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | The user who started typing |

**Success Response (200):**

```json
{ "success": true }
```

**Side Effects:**
- Broadcasts `typing` WebSocket event with `is_typing: true` to the conversation channel

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 403 | Not a participant |

---

### 4.2 Stop Typing

```
POST /conversations/{id}/typing/stop
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | The user who stopped typing |

**Success Response (200):**

```json
{ "success": true }
```

**Side Effects:**
- Broadcasts `typing` WebSocket event with `is_typing: false`

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 403 | Not a participant |

---

### 4.3 Get Typing Status (Polling Fallback)

```
GET /conversations/{id}/typing?user_id={userId}
```

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "typing_users": [
      { "id": 5, "first_name": "John", "last_name": "Doe" }
    ]
  }
}
```

**Notes:** Users whose `typing_started_at` is older than 5 seconds are automatically excluded.

---

## 5. Conversation State

### 5.1 Toggle Pin

```
POST /conversations/{id}/pin
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | The user toggling pin |

**Success Response (200):**

```json
{
  "success": true,
  "message": "Conversation pinned",
  "data": { "is_pinned": true }
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 403 | Not a participant |
| 422 | Validation error |

---

### 5.2 Toggle Favorite

```
POST /conversations/{id}/favorite
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | The user toggling favorite |

**Success Response (200):**

```json
{
  "success": true,
  "message": "Added to favorites",
  "data": { "is_favorite": true }
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 403 | Not a participant |
| 422 | Validation error |

---

### 5.3 Toggle Archive

```
POST /conversations/{id}/archive
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | The user toggling archive |

**Success Response (200):**

```json
{
  "success": true,
  "message": "Conversation archived",
  "data": { "is_archived": true }
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 403 | Not a participant |
| 422 | Validation error |

---

### 5.4 Set Folder

```
POST /conversations/{id}/folder
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | The user setting the folder |
| `folder` | string\|null | No | Folder name (max 50 chars). Pass `null` to remove from folder. |

**Success Response (200):**

```json
{
  "success": true,
  "message": "Moved to folder: Work",
  "data": { "folder": "Work" }
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 403 | Not a participant |
| 422 | Validation error |

---

## 6. Group Participant Management

> All endpoints below require the requesting user (`user_id`) to be an **admin** of the group conversation.

### 6.1 Add Participant

```
POST /conversations/{id}/participants
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | Admin making the request |
| `participant_id` | int | Yes | User ID to add |

**Success Response (200):**

```json
{
  "success": true,
  "message": "Participant added",
  "data": { /* full conversation object */ }
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 400 | Cannot add to a private conversation |
| 403 | Requester is not an admin |
| 404 | Conversation not found |
| 422 | Validation error |

**Notes:** If the user is already a participant, returns success with `"User is already a participant"`.

---

### 6.2 Remove Participant

```
DELETE /conversations/{id}/participants/{participantUserId}
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | Admin making the request |

**Success Response (200):**

```json
{
  "success": true,
  "message": "Participant removed"
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 400 | Cannot remove from private conversation |
| 400 | Admin trying to remove themselves (use `DELETE /conversations/{id}` to leave) |
| 403 | Requester is not an admin |
| 404 | Conversation or participant not found |
| 422 | Validation error |

---

### 6.3 Promote to Admin

```
POST /conversations/{id}/participants/{participantUserId}/promote
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | Admin making the request |

**Success Response (200):**

```json
{
  "success": true,
  "message": "Participant promoted to admin",
  "data": { "is_admin": true }
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 403 | Requester is not an admin |
| 404 | Group conversation not found, or participant not found |
| 422 | Validation error |

---

### 6.4 Demote Admin

```
POST /conversations/{id}/participants/{participantUserId}/demote
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | Admin making the request |

**Success Response (200):**

```json
{
  "success": true,
  "message": "Admin demoted to participant",
  "data": { "is_admin": false }
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 400 | Cannot demote the last admin |
| 403 | Requester is not an admin |
| 404 | Group conversation not found, or participant not found |
| 422 | Validation error |

---

## 7. Reports

### 7.1 Report Conversation

```
POST /conversations/{id}/report
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | Reporter's user ID |
| `reason` | string | Yes | Reason for report (max 1000 chars) |
| `category` | string | No | Category (max 50 chars, default: `conversation`) |

**Success Response (200):**

```json
{
  "success": true,
  "message": "Report submitted"
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 404 | Conversation not found |
| 422 | Validation error |

---

### 7.2 Report Message

```
POST /conversations/{id}/messages/{messageId}/report
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | Reporter's user ID |
| `reason` | string | Yes | Reason for report (max 1000 chars) |
| `category` | string | No | Category (max 50 chars, default: `message`) |

**Success Response (200):**

```json
{
  "success": true,
  "message": "Report submitted"
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 404 | Message not found |
| 422 | Validation error |

---

## 8. Online Status

### 8.1 Get Online Participants

```
GET /conversations/{id}/online
```

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "total_participants": 5,
    "online_count": 2,
    "online_user_ids": [5, 12]
  }
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 404 | Conversation not found |

---

## 9. Calls (1-on-1)

### 9.1 Initiate Call

```
POST /calls
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `caller_id` | int | Yes | Caller's user ID |
| `callee_id` | int | Yes | Callee's user ID |
| `type` | string | Yes | `voice` or `video` |

**Success Response (201):**

```json
{
  "success": true,
  "data": {
    "id": 1,
    "caller_id": 5,
    "callee_id": 12,
    "type": "video",
    "status": "ringing",
    "call_id": "uuid-string",
    "started_at": null,
    "ended_at": null,
    "created_at": "2026-02-14T10:30:00.000000Z",
    "caller": { "id": 5, "first_name": "John", "last_name": "Doe", /* ... */ },
    "callee": { "id": 12, "first_name": "Jane", "last_name": "Doe", /* ... */ }
  }
}
```

**Side Effects:**
- Broadcasts `call_state_changed` WebSocket event to both users' private channels
- Dispatches FCM push notification (`incoming_call`) to the callee

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 400 | Callee is already in another call (`status: "busy"`) |
| 403 | Either user has blocked the other |
| 422 | Validation error |

---

### 9.2 Answer Call

```
POST /calls/{id}/answer
```

No request body required.

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "id": 1,
    "status": "answered",
    "started_at": "2026-02-14T10:30:15.000000Z",
    "caller": { /* ... */ },
    "callee": { /* ... */ }
  }
}
```

**Side Effects:**
- Broadcasts `call_state_changed` WebSocket event (state: `answered`)

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 400 | Call is not in `ringing` status |

---

### 9.3 Decline Call

```
POST /calls/{id}/decline
```

No request body required.

**Success Response (200):**

```json
{
  "success": true,
  "message": "Simu imekataliwa"
}
```

**Side Effects:**
- Broadcasts `call_state_changed` WebSocket event (state: `declined`)

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 400 | Call is not in `pending` or `ringing` status |

---

### 9.4 End Call

```
POST /calls/{id}/end
```

**Request Body (optional):**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `reason` | string | No | End reason (default: `completed`) |

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "id": 1,
    "status": "ended",
    "ended_at": "2026-02-14T10:35:00.000000Z",
    "end_reason": "completed",
    "duration": 285
  }
}
```

**Side Effects:**
- Broadcasts `call_state_changed` WebSocket event (state: `ended`)

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 400 | Call is not active |

---

### 9.5 Get Call Details

```
GET /calls/{id}
```

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "id": 1,
    "caller_id": 5,
    "callee_id": 12,
    "type": "video",
    "status": "ended",
    "caller": { /* ... */ },
    "callee": { /* ... */ }
  }
}
```

---

### 9.6 Get Call History

```
GET /calls/history/{userId}
```

**Query Parameters:**

| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `type` | string | No | Filter by `voice` or `video` |
| `direction` | string | No | Filter by `incoming` or `outgoing` |
| `per_page` | int | No | Items per page (default: 30) |

**Success Response (200):**

```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "user_id": 5,
      "other_user_id": 12,
      "type": "video",
      "direction": "outgoing",
      "status": "completed",
      "duration": 285,
      "call_time": "2026-02-14T10:30:00.000000Z",
      "other_user": {
        "id": 12,
        "first_name": "Jane",
        "last_name": "Doe",
        "username": "janedoe",
        "profile_photo_path": "photos/jane.jpg"
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 2,
    "per_page": 30,
    "total": 45
  }
}
```

---

### 9.7 Get Incoming Call (Polling)

```
GET /calls/incoming/{userId}
```

**Success Response (200) — Call ringing:**

```json
{
  "success": true,
  "has_incoming": true,
  "data": {
    "id": 5,
    "caller_id": 12,
    "callee_id": 5,
    "type": "voice",
    "status": "ringing",
    "caller": { "id": 12, "first_name": "Jane", /* ... */ }
  }
}
```

**Success Response (200) — No incoming:**

```json
{
  "success": true,
  "has_incoming": false,
  "data": null
}
```

---

### 9.8 Mark Call as Missed

```
POST /calls/{id}/missed
```

No request body required.

**Success Response (200):**

```json
{ "success": true }
```

**Side Effects:**
- Broadcasts `call_state_changed` WebSocket event (state: `missed`)
- Dispatches FCM push notification (`call_missed`) to the callee

---

## 10. Group Calls

### 10.1 Start Group Call

```
POST /calls/group
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `conversation_id` | int | Yes | Conversation to call in |
| `initiated_by` | int | Yes | User starting the call |
| `type` | string | Yes | `voice` or `video` |

**Success Response (201):**

```json
{
  "success": true,
  "data": {
    "id": 1,
    "conversation_id": 3,
    "initiated_by": 5,
    "type": "video",
    "status": "active",
    "call_id": "uuid-string",
    "max_participants": 8,
    "participants": [
      {
        "id": 1,
        "group_call_id": 1,
        "user_id": 5,
        "status": "joined",
        "is_muted": false,
        "is_video_off": false,
        "user": { "id": 5, "first_name": "John", /* ... */ }
      },
      {
        "id": 2,
        "group_call_id": 1,
        "user_id": 12,
        "status": "invited",
        "user": { "id": 12, "first_name": "Jane", /* ... */ }
      }
    ]
  }
}
```

**Notes:** If there's already an active group call for this conversation, returns the existing one (200) instead of creating a new one.

**Side Effects:**
- Sends FCM push notification (`group_call_invite`) to all invited participants

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 422 | Validation error |

---

### 10.2 Join Group Call

```
POST /calls/group/{id}/join
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | User joining |

**Success Response (200):**

```json
{
  "success": true,
  "data": { /* group call with participants */ }
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 400 | Call is not active |

---

### 10.3 Leave Group Call

```
POST /calls/group/{id}/leave
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | User leaving |

**Success Response (200):**

```json
{ "success": true }
```

---

### 10.4 Decline Group Call

```
POST /calls/group/{id}/decline
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | User declining |

**Success Response (200):**

```json
{ "success": true }
```

---

### 10.5 End Group Call

```
POST /calls/group/{id}/end
```

No request body required.

**Success Response (200):**

```json
{ "success": true }
```

---

### 10.6 Toggle Mute (Group Call)

```
POST /calls/group/{id}/mute
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | User toggling mute |

**Success Response (200):**

```json
{
  "success": true,
  "is_muted": true
}
```

---

### 10.7 Toggle Video (Group Call)

```
POST /calls/group/{id}/video
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | User toggling video |

**Success Response (200):**

```json
{
  "success": true,
  "is_video_off": true
}
```

---

### 10.8 Get Active Group Call

```
GET /calls/group/active/{conversationId}
```

**Success Response (200) — Active call:**

```json
{
  "success": true,
  "has_active_call": true,
  "data": { /* group call with participants */ }
}
```

**Success Response (200) — No active call:**

```json
{
  "success": true,
  "has_active_call": false,
  "data": null
}
```

---

### 10.9 Invite User to Group Call

```
POST /calls/group/{id}/invite
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | The inviter's user ID |
| `invitee_user_id` | int | Yes | The user being invited |

**Success Response (200):**

```json
{
  "success": true,
  "message": "User invited",
  "data": { /* group call with participants */ }
}
```

**Side Effects:**
- Sends FCM push notification (`group_call_invite`) to the invitee

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 400 | Call is not active |
| 400 | Maximum participants reached |
| 422 | Validation error |

---

## 11. Block/Unblock

### 11.1 Block User

```
POST /users/block
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | The blocker's user ID |
| `blocked_user_id` | int | Yes | The user to block |

**Success Response (200):**

```json
{
  "success": true,
  "message": "User blocked"
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 400 | Trying to block yourself |
| 422 | Validation error |

**Notes:** If already blocked, returns success with `"User already blocked"`.

---

### 11.2 Unblock User

```
POST /users/unblock
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | The user unblocking |
| `blocked_user_id` | int | Yes | The user to unblock |

**Success Response (200):**

```json
{
  "success": true,
  "message": "User unblocked"
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 422 | Validation error |

---

### 11.3 List Blocked Users

```
GET /users/blocked?user_id={userId}
```

**Success Response (200):**

```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "blocked_user_id": 12,
      "created_at": "2026-02-14T08:00:00.000000Z",
      "user": {
        "id": 12,
        "first_name": "Jane",
        "last_name": "Doe",
        "username": "janedoe",
        "profile_photo_path": "photos/jane.jpg"
      }
    }
  ]
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 400 | `user_id` not provided |

---

### 11.4 Check Block Status

```
GET /users/blocked/{otherUserId}/check?user_id={userId}
```

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "is_blocked": true,
    "is_blocked_by": false
  }
}
```

**Notes:**
- `is_blocked`: whether you have blocked the other user
- `is_blocked_by`: whether the other user has blocked you

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 400 | `user_id` not provided |

---

## 12. Presence & FCM Tokens

### 12.1 Heartbeat (Online)

```
POST /presence/heartbeat
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | The user's ID |

**Success Response (200):**

```json
{ "success": true }
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 422 | Validation error |

---

### 12.2 Go Offline

```
POST /presence/offline
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | The user's ID |

**Success Response (200):**

```json
{ "success": true }
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 422 | Validation error |

---

### 12.3 Store FCM Token

```
POST /fcm-token
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | The user's ID |
| `token` | string | Yes | FCM device token (also accepts `fcm_token`) |
| `device_id` | string | No | Device identifier (default: `default`) |
| `platform` | string | No | `ios`, `android`, or `web` |

**Success Response (200):**

```json
{
  "success": true,
  "message": "FCM token stored"
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 422 | `token`/`fcm_token` missing, or `user_id` invalid |

---

### 12.4 Remove FCM Token

```
DELETE /fcm-token
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | int | Yes | The user's ID |
| `device_id` | string | No | Device identifier (default: `default`) |

**Success Response (200):**

```json
{
  "success": true,
  "message": "FCM token removed"
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 422 | Validation error |

---

## 13. WebSocket Events

Connect via Laravel Reverb WebSocket. Events are broadcast on private channels.

### Channels

| Channel | Description |
|---------|-------------|
| `private-conversation.{conversationId}` | All message/typing events for a conversation |
| `private-user.{userId}` | Call state events for a specific user |

### Events

#### `new_message` (channel: `conversation.{id}`)

```json
{
  "message": {
    "id": 101,
    "conversation_id": 1,
    "sender_id": 5,
    "content": "Hello!",
    "message_type": "text",
    "media_path": null,
    "reply_to_id": null,
    "forward_message_id": null,
    "edited_at": null,
    "created_at": "...",
    "sender": { "id": 5, "first_name": "John", "last_name": "Doe", /* ... */ },
    "reply_to": null,
    "reactions": []
  }
}
```

#### `message_updated` (channel: `conversation.{id}`)

```json
{
  "message": { /* message object */ },
  "update_type": "edited"
}
```

`update_type` can be `"edited"` or `"reaction"`.

#### `message_deleted` (channel: `conversation.{id}`)

```json
{
  "conversation_id": 1,
  "message_id": 101,
  "deleted_by": 5
}
```

#### `typing` (channel: `conversation.{id}`)

```json
{
  "conversation_id": 1,
  "user_id": 5,
  "first_name": "John",
  "last_name": "Doe",
  "is_typing": true
}
```

#### `call_state_changed` (channels: `user.{callerId}` + `user.{calleeId}`)

```json
{
  "call": { /* full call object with caller and callee */ },
  "state": "ringing"
}
```

`state` values: `ringing`, `answered`, `declined`, `ended`, `missed`

---

## FCM Push Notification Payloads

### New Message (`new_message`)

```json
{
  "data": {
    "type": "new_message",
    "conversation_id": "1",
    "message_id": "101",
    "sender_id": "5",
    "sender_name": "John Doe",
    "sender_avatar": "photos/john.jpg",
    "message_type": "text",
    "content": "Hello!"
  }
}
```

### Incoming Call (`incoming_call`)

```json
{
  "data": {
    "type": "incoming_call",
    "call_id": "1",
    "call_uuid": "uuid-string",
    "caller_id": "5",
    "callee_id": "12",
    "call_type": "video",
    "caller_name": "John Doe",
    "caller_avatar": "photos/john.jpg"
  }
}
```

### Call Missed (`call_missed`)

Same structure as `incoming_call` but with `"type": "call_missed"`.

### Group Call Invite (`group_call_invite`)

```json
{
  "data": {
    "type": "group_call_invite",
    "group_call_id": "1",
    "call_uuid": "uuid-string",
    "conversation_id": "3",
    "initiator_id": "5",
    "call_type": "video",
    "initiator_name": "John Doe",
    "initiator_avatar": "photos/john.jpg"
  }
}
```

---

## Common Error Response Shape

All error responses follow this format:

```json
{
  "success": false,
  "message": "Human-readable error message",
  "errors": {
    "field_name": ["Specific validation error"]
  }
}
```

The `errors` field is only present for 422 validation errors.

## HTTP Status Codes Used

| Code | Meaning |
|------|---------|
| 200 | Success |
| 201 | Created (new message, new conversation, new call) |
| 400 | Bad request (missing required query param, invalid state) |
| 403 | Forbidden (not a participant, blocked, not admin) |
| 404 | Not found (conversation, message, user, call) |
| 422 | Validation error (invalid/missing fields) |
| 500 | Server error (database failures, unexpected errors) |
