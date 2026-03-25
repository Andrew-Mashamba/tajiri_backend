# Laravel Backend: Firebase Live-Update Notifications (Directive)

The Flutter app listens to **Firestore** at `updates/{userId}`. When your Laravel backend **writes** to that document after a database change, the app **refetches data** and the **UI updates instantly**. This document defines **which API actions must trigger a Firestore write** and **for which user(s)**.

---

## 1. Firestore document shape (contract)

- **Collection**: `updates`
- **Document ID**: `{userId}` (integer as string, e.g. `"42"`)
- **Fields** (write/merge these on each notification):

| Field     | Type    | Required | Description |
|-----------|---------|----------|-------------|
| `ts`      | number  | Yes      | Server timestamp (e.g. `now()->timestamp` or milliseconds). |
| `event`   | string  | Yes      | One of: `feed_updated`, `post_updated`, `profile_updated`, `followers_updated`, `messages_updated`, `stories_updated`. |
| `payload` | object  | No       | Extra data. For `post_updated`: `{"post_id": 123}`. For `messages_updated`: `{"conversation_id": 456}`. |

**Important**: Overwrite or merge the document for each target user. The app only needs to know "something changed"; it will then refetch from your REST API. Do **not** store business data in Firestore.

---

## 2. Laravel implementation (high level)

1. **Install Firebase Admin SDK** for Laravel (e.g. `kreait/firebase-php-sdk`).
2. **Obtain a service account key** from Firebase Console → Project Settings → Service accounts → Generate new private key. Store the JSON path in `.env` (e.g. `FIREBASE_CREDENTIALS=/path/to/key.json`).
3. **Create a small helper** (e.g. `FirebaseLiveUpdateService` or trait) that:
   - Initializes Firestore with the service account.
   - Exposes a method: `notifyUser(int $userId, string $event, array $payload = [])` that writes/merges to `updates/{userId}` with `ts`, `event`, `payload`.
   - Optionally: `notifyUsers(array $userIds, string $event, array $payload = [])` to loop and notify multiple users.
4. **Call this helper** from your existing controllers/services **after** the DB transaction (or after the HTTP response is prepared) for each action listed below.

---

## 3. Actions that MUST trigger a notification

Below, "notify" means: call your Firestore helper to write to the document `updates/{userId}` with the given `event` (and optional `payload`) for **each** listed user.

---

### 3.1 Posts

| API action (Laravel) | When (after DB change) | Notify whom | Event | Payload |
|----------------------|-------------------------|-------------|--------|---------|
| **Create post** | New post saved | All **followers** of the author; and the **author** | `feed_updated` | `{}` |
| **Update post** | Post updated | **Author** | `post_updated` | `{"post_id": <id>}` |
| **Delete post** | Post deleted | **Author**; optionally all followers | `feed_updated` and/or `post_updated` | `{"post_id": <id>}` |
| **Like post** | Like saved | **Post author** | `post_updated` | `{"post_id": <id>}` |
| **Unlike post** | Like removed | **Post author** | `post_updated` | `{"post_id": <id>}` |
| **Add comment** | Comment saved | **Post author** | `post_updated` | `{"post_id": <id>}` |
| **Delete comment** | Comment deleted | **Post author** | `post_updated` | `{"post_id": <post_id>}` |
| **Update comment** | Comment updated | **Post author** | `post_updated` | `{"post_id": <post_id>}` |
| **Like/unlike comment** | Comment like saved/removed | **Post author** | `post_updated` | `{"post_id": <post_id>}` |
| **Pin/unpin comment** | Pin state changed | **Post author** | `post_updated` | `{"post_id": <id>}` |
| **Share post** | New "shared" post created | **Author of new share**; **followers**; original post author | `feed_updated` / `post_updated` | |
| **Save/unsave post** | Save record created/removed | **User** (optional) | `feed_updated` | — |

### 3.2 Friends / follow

| API action (Laravel) | When | Notify whom | Event | Payload |
|----------------------|------|-------------|--------|---------|
| **Send friend request** | Request created | **Recipient** | `profile_updated` | `{}` |
| **Accept friend request** | Friendship created | **Requester** and **accepter** | `profile_updated` and `feed_updated` | `{}` |
| **Decline friend request** | Request declined | **Requester** | `profile_updated` | `{}` |
| **Cancel friend request** | Request cancelled | **Recipient** | `profile_updated` | `{}` |
| **Remove friend** | Friendship removed | **Other user** | `profile_updated` and `feed_updated` | `{}` |

### 3.3 Stories

| API action (Laravel) | When | Notify whom | Event | Payload |
|----------------------|------|-------------|--------|---------|
| **Create story** | Story saved | **Followers** of author | `stories_updated` | `{}` |
| **Delete story** | Story deleted | **Followers** of author | `stories_updated` | `{}` |

### 3.4 Messages / conversations

| API action (Laravel) | When | Notify whom | Event | Payload |
|----------------------|------|-------------|--------|---------|
| **Create group conversation** | Conversation saved | All **participants** (except creator) | `messages_updated` | `{"conversation_id": <id>}` |
| **Send message** | Message saved | All **other participants** | `messages_updated` | `{"conversation_id": <id>}` |
| **Leave conversation** | Participant removed | **Other participants** | `messages_updated` | `{"conversation_id": <id>}` |

### 3.5 Profile / user

| API action (Laravel) | When | Notify whom | Event | Payload |
|----------------------|------|-------------|--------|---------|
| **Update profile** | Profile updated | **That user**; optionally **followers** | `profile_updated` | `{}` |

### 3.6 Clips (shorts)

Same pattern as posts: `feed_updated` for new clip, `post_updated` for interactions.

---

## 4. Summary

| Event               | Meaning for the app | Typical triggers |
|---------------------|---------------------|-------------------|
| `feed_updated`      | Refetch feed (and stories) | New/delete post, new/delete story, share post, friend accept/remove |
| `post_updated`      | Refetch a specific post | Like/unlike, add/edit/delete comment, pin comment, update/delete post |
| `profile_updated`   | Refetch profile | Profile edit, friend request/accept/decline/cancel/remove |
| `followers_updated` | Follow list changed | Follow/unfollow, friend request/accept |
| `messages_updated`  | Refetch conversations | New message, new group, leave conversation |
| `stories_updated`   | Refetch stories | New/delete story |
