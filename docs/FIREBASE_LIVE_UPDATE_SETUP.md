# Firebase Live-Update Notifications — Backend Implementation Guide

> **For: Flutter Frontend Team**
> **Date: 2026-02-14**
> **Status: Backend Complete & Tested**

---

## Overview

The Laravel backend now writes to **Firestore** whenever data changes that the Flutter app should know about. Instead of polling the REST API, the app listens to a single Firestore document and refetches data when it changes.

**Flow:**
```
User action → Laravel API → DB write → Firestore write → Flutter listener triggers → App refetches from REST API → UI updates
```

---

## 1. Firestore Document Contract

- **Collection:** `updates`
- **Document ID:** `{userId}` (the user's `user_profiles.id` as a string, e.g. `"42"`)
- **Firestore Project:** `tajiri-6d6ae`

### Fields

| Field     | Type     | Always present | Description |
|-----------|----------|----------------|-------------|
| `ts`      | integer  | Yes            | Server timestamp in **milliseconds** (e.g. `1739523600000`). Always increases — use this to detect changes. |
| `event`   | string   | Yes            | The event name (see table below). Tells the app **what** changed. |
| `payload` | map      | No             | Extra context. Only present when relevant (e.g. `{"post_id": 123}`). |

### Example document at `updates/42`:
```json
{
  "ts": 1739523600000,
  "event": "post_updated",
  "payload": {
    "post_id": 587
  }
}
```

---

## 2. Event Types

| Event               | What changed                          | Recommended app action |
|---------------------|---------------------------------------|------------------------|
| `feed_updated`      | A post was created, deleted, or shared; a friend was added/removed | Refetch the feed (For You, Following, Friends tab). Also refetch stories if on Friends tab. |
| `post_updated`      | A specific post changed (liked, commented, edited, deleted) | Refetch that specific post by `payload.post_id`. If the post detail screen is open, refresh it. |
| `profile_updated`   | User's profile changed, or a friend request was sent/accepted/declined/cancelled | Refetch the user's profile, friends list, or pending requests. |
| `followers_updated` | Follow/unfollow happened              | Refetch followers/following list. |
| `messages_updated`  | New message, new group chat, or someone left a conversation | Refetch conversations list. If a chat is open and `payload.conversation_id` matches, refetch that conversation's messages. |
| `stories_updated`   | A friend posted or deleted a story    | Refetch the stories list (the row of story circles). |

---

## 3. Payload Details

| Event              | Payload fields             | Example |
|--------------------|----------------------------|---------|
| `post_updated`     | `post_id` (integer)        | `{"post_id": 587}` |
| `messages_updated` | `conversation_id` (integer)| `{"conversation_id": 42}` |
| `feed_updated`     | *(none)*                   | `{}` |
| `profile_updated`  | *(none)*                   | `{}` |
| `followers_updated`| *(none)*                   | `{}` |
| `stories_updated`  | *(none)*                   | `{}` |

---

## 4. Which API Actions Trigger Which Events

### 4.1 Posts

| API Endpoint | Action | Event | Notified Users | Payload |
|-------------|--------|-------|----------------|---------|
| `POST /api/posts` | Create post | `feed_updated` | Author + all friends | — |
| `PUT /api/posts/{id}` | Update post | `post_updated` | Author | `{post_id}` |
| `DELETE /api/posts/{id}` | Delete post | `post_updated` + `feed_updated` | Author + all friends | `{post_id}` |
| `POST /api/posts/{id}/like` | Like post | `post_updated` | Post author | `{post_id}` |
| `DELETE /api/posts/{id}/like` | Unlike post | `post_updated` | Post author | `{post_id}` |
| `POST /api/posts/{id}/share` | Share post | `feed_updated` + `post_updated` | Sharer + sharer's friends + original author | `{post_id}` for original |

### 4.2 Comments

| API Endpoint | Action | Event | Notified Users | Payload |
|-------------|--------|-------|----------------|---------|
| `POST /api/posts/{id}/comments` | Add comment | `post_updated` | Post author | `{post_id}` |
| `PUT /api/comments/{id}` | Edit comment | `post_updated` | Post author | `{post_id}` |
| `DELETE /api/comments/{id}` | Delete comment | `post_updated` | Post author | `{post_id}` |
| `POST /api/comments/{id}/like` | Like comment | `post_updated` | Post author | `{post_id}` |
| `DELETE /api/comments/{id}/like` | Unlike comment | `post_updated` | Post author | `{post_id}` |
| `POST /api/posts/{postId}/comments/{commentId}/pin` | Pin comment | `post_updated` | Post author | `{post_id}` |
| `DELETE /api/posts/{postId}/comments/pin` | Unpin comment | `post_updated` | Post author | `{post_id}` |

### 4.3 Friends

| API Endpoint | Action | Event | Notified Users |
|-------------|--------|-------|----------------|
| `POST /api/friends/request` | Send request | `profile_updated` | Recipient |
| `POST /api/friends/accept/{id}` | Accept request | `profile_updated` + `feed_updated` | Both users |
| `POST /api/friends/decline/{id}` | Decline request | `profile_updated` | Requester |
| `POST /api/friends/cancel/{id}` | Cancel request | `profile_updated` | Recipient |
| `DELETE /api/friends/{id}` | Remove friend | `profile_updated` + `feed_updated` | Other user |

### 4.4 Stories

| API Endpoint | Action | Event | Notified Users |
|-------------|--------|-------|----------------|
| `POST /api/stories` | Create story | `stories_updated` | All friends |
| `DELETE /api/stories/{id}` | Delete story | `stories_updated` | All friends |

### 4.5 Messages

| API Endpoint | Action | Event | Notified Users | Payload |
|-------------|--------|-------|----------------|---------|
| `POST /api/conversations` | Create group | `messages_updated` | All participants (except creator) | `{conversation_id}` |
| `POST /api/conversations/{id}/messages` | Send message | `messages_updated` | All other participants | `{conversation_id}` |
| `DELETE /api/conversations/{id}` | Leave conversation | `messages_updated` | Remaining participants | `{conversation_id}` |

### 4.6 Profile

| API Endpoint | Action | Event | Notified Users |
|-------------|--------|-------|----------------|
| `PUT /api/users/{phone}` | Update profile | `profile_updated` | That user |
| `POST /api/users/{id}/profile-photo` | Update profile photo | `profile_updated` | That user + all friends |
| `POST /api/users/{id}/cover-photo` | Update cover photo | `profile_updated` | That user |
| `PUT /api/users/{id}/bio` | Update bio | `profile_updated` | That user |
| `PUT /api/users/{id}/username` | Update username | `profile_updated` | That user |

### 4.7 Clips

| API Endpoint | Action | Event | Notified Users | Payload |
|-------------|--------|-------|----------------|---------|
| `POST /api/clips` | Create clip | `feed_updated` | Author + all friends | — |
| `DELETE /api/clips/{id}` | Delete clip | `feed_updated` | Author + all friends | — |
| `POST /api/clips/{id}/like` | Like clip | `post_updated` | Clip author | `{post_id}` (clip id) |
| `DELETE /api/clips/{id}/like` | Unlike clip | `post_updated` | Clip author | `{post_id}` (clip id) |
| `POST /api/clips/{id}/comments` | Comment on clip | `post_updated` | Clip author | `{post_id}` (clip id) |
| `POST /api/clips/{id}/share` | Share clip | `post_updated` | Clip author | `{post_id}` (clip id) |
| `POST /api/clips/{clipId}/comments/{commentId}/like` | Like clip comment | `post_updated` | Clip author | `{post_id}` (clip id) |

---

## 5. Flutter Implementation Guide

### 5.1 Listen to Firestore

After the user logs in (you have their `user_profiles.id`), set up a **snapshot listener** on their document:

```dart
import 'package:cloud_firestore/cloud_firestore.dart';

class LiveUpdateService {
  StreamSubscription? _subscription;

  void startListening(int userId) {
    _subscription = FirebaseFirestore.instance
        .collection('updates')
        .doc(userId.toString())
        .snapshots()
        .listen((snapshot) {
      if (!snapshot.exists) return;

      final data = snapshot.data()!;
      final event = data['event'] as String?;
      final payload = data['payload'] as Map<String, dynamic>? ?? {};
      final ts = data['ts'] as int?;

      if (event == null || ts == null) return;

      _handleEvent(event, payload);
    });
  }

  void stopListening() {
    _subscription?.cancel();
  }

  void _handleEvent(String event, Map<String, dynamic> payload) {
    switch (event) {
      case 'feed_updated':
        // Refetch feed data
        // e.g. feedProvider.refresh();
        break;
      case 'post_updated':
        final postId = payload['post_id'] as int?;
        if (postId != null) {
          // Refetch this specific post
          // e.g. postProvider.refreshPost(postId);
        }
        break;
      case 'profile_updated':
        // Refetch profile, friends list, pending requests
        // e.g. profileProvider.refresh();
        break;
      case 'followers_updated':
        // Refetch followers/following
        break;
      case 'messages_updated':
        final conversationId = payload['conversation_id'] as int?;
        // Refetch conversations list
        // If chat screen is open for this conversationId, refetch messages
        break;
      case 'stories_updated':
        // Refetch stories list
        // e.g. storiesProvider.refresh();
        break;
    }
  }
}
```

### 5.2 Key Points

1. **Only listen, never write.** The Flutter app should NEVER write to `updates/{userId}`. Only the backend writes.

2. **Deduplicate with `ts`.** Store the last seen `ts` value locally. If a snapshot fires with the same `ts`, ignore it (prevents duplicate refetches on app restart).

3. **Don't store business data.** The Firestore document only signals "something changed." Always refetch from the REST API to get actual data.

4. **Start listening after login, stop on logout.**

5. **The document gets overwritten** each time. Only the latest event is stored. If multiple events fire quickly, the app may only see the last one. This is fine — a full feed refetch covers any missed intermediate events.

6. **Payload is optional.** Always check if `payload` and its fields exist before using them.

### 5.3 Firestore Security Rules

These rules should already be set. The app authenticates with Firebase Auth, and each user can only read their own document:

```
rules_version = '2';
service cloud.firestore {
  match /databases/{database}/documents {
    match /updates/{userId} {
      allow read: if request.auth != null && request.auth.uid == userId;
      allow write: if false;
    }
  }
}
```

> **Note:** If your Flutter app uses `user_profiles.id` (integer) as the document ID but Firebase Auth uses a different UID format, you'll need to adjust the security rules accordingly. One approach: store the mapping in the user's Firebase Auth custom claims, or use a simpler rule like `allow read: if request.auth != null;` during development.

### 5.4 Firebase Configuration

- **Project ID:** `tajiri-6d6ae`
- **Firestore collection:** `updates`
- Add the Firebase config files (`google-services.json` for Android, `GoogleService-Info.plist` for iOS) from the Firebase Console.

---

## 6. Testing

You can manually trigger a Firestore write to test your Flutter listener:

```bash
# From the server
php artisan tinker --execute="
app(\App\Services\Firebase\FirebaseLiveUpdateService::class)
    ->notifyUser(YOUR_USER_ID, 'feed_updated');
"
```

Replace `YOUR_USER_ID` with an actual `user_profiles.id`. The Flutter app listening on that user's document should immediately detect the change.

---

## 7. Architecture Diagram

```
┌─────────────┐     HTTP      ┌─────────────────┐
│ Flutter App  │ ───────────► │  Laravel API     │
│             │               │                  │
│  Firestore  │ ◄──────────  │  Firestore Write │
│  Listener   │   snapshot    │  (after DB ops)  │
│             │               │                  │
│  Refetch    │ ───────────► │  REST Endpoints  │
│  from API   │     HTTP      │                  │
└─────────────┘               └─────────────────┘
```

The backend writes to Firestore using the **REST API** with a service account (no gRPC dependency). Writes happen inline after each database operation. All writes are fire-and-forget with error handling — if Firestore is temporarily unavailable, the API response is not affected.
