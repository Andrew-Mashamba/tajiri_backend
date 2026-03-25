# Backend Directive: Full Support for Video & Audio Calls (Tajiri Flutter)

**Audience:** Backend developers
**Purpose:** Single source of truth for API, WebSocket, and push contracts required so the **Tajiri Flutter app** can use all call features end-to-end.
**Date:** 2025-02-16
**Related:** [implementation-plan.md](implementation-plan.md) | [backend-requirements/](backend-requirements/README.md)

---

## 1. Overview

The Flutter client expects a **REST + WebSocket + FCM** backend. All call APIs MUST:

- Use **Bearer token** authentication (`Authorization: Bearer {token}`). The app stores the token after login/register and sends it on every call request.
- Use the **base path** under which the app is configured (e.g. `https://your-api.com/api`). All paths below are relative to that base.

The app uses:

- **REST** for create, accept, reject, end, signaling, TURN, call log, participants, reactions, raise hand, missed-call voice message, and scheduled calls.
- **WebSocket (Laravel Reverb)** for real-time events on `private-call.{call_id}`. The app subscribes after receiving `call_id` (from REST or push).
- **FCM** for incoming-call notifications when the app is in background or killed. The app opens the incoming-call screen when it receives a data message with `type: "call_incoming"` and the payload in § 5.

Implement the endpoints and events in this document so the Flutter app works without changes.

---

## 2. Authentication

| Context | How the app sends auth |
|---------|-------------------------|
| REST | `Authorization: Bearer {access_token}`. Optional: `user_id` in body/query for legacy. |
| WebSocket channel auth | App calls `POST {broadcastAuthBaseUrl}/broadcasting/auth` with body `{ "socket_id": "...", "channel_name": "private-call.{callId}" }` and header `Authorization: Bearer {access_token}`. Backend must return 200 with JSON including `auth` string for Reverb to allow subscription. |
| FCM | No auth in the push payload; device is identified by FCM token (stored per user/device). |

**Channel authorization rule:** Allow subscription to `private-call.{call_id}` only if the authenticated user is a participant of that call (caller, callee, or in `call_participants`).

---

## 3. REST API — Endpoints Used by the App

Base URL = app's API base (e.g. `https://zima-uat.site:8003/api`). Paths are relative to that.

### 3.1 Calls (1:1 and group)

| Method | Path | Purpose | Request body (JSON) | Response |
|--------|------|---------|---------------------|----------|
| `POST` | `/calls` | Create 1:1 call | `{ "callee_id": int, "type": "voice" \| "video" }` | `201`: `{ "call_id", "status", "type", "ice_servers": [...], "created_at" }` |
| `POST` | `/calls` | Create group call | `{ "group_id": int, "invited_user_ids": [int], "type": "voice" \| "video" }` | Same as above; include `ice_servers`. |
| `POST` | `/calls/{id}/accept` | Accept call | Optional: `{ "sdp_answer": { "type", "sdp" } }` | `200`: `{ "call_id", "status", "ice_servers", "started_at" }` |
| `POST` | `/calls/{id}/reject` | Reject call | (empty or `{}`) | `200`: `{ "call_id", "status" }` |
| `POST` | `/calls/{id}/end` | End call | (empty or `{}`) | `200`: `{ "call_id", "status", "ended_at", "duration_seconds" }` |
| `POST` | `/calls/{id}/signaling` | Send SDP or ICE | `{ "type": "offer" \| "answer" \| "ice_candidate", "sdp"?: {...}, "candidate"?: {...} }` | `200` or `202` |
| `GET` | `/calls/turn-credentials` | TURN/STUN for WebRTC | — | `200`: `{ "ice_servers": [...] }` |
| `GET` | `/calls` | Call log (paginated) | Query: `page`, `per_page`, optional `type`, `direction` | `200`: `{ "data": [ { "call_id", "type", "status", "direction", "other_party", "started_at", "ended_at", "duration_seconds", "created_at" } ], "meta": { "current_page", "per_page", "total" } }` |
| `POST` | `/calls/{id}/participants` | Add participant | `{ "user_id": int }` | `201` |
| `POST` | `/calls/{id}/leave` | Leave call | (empty or `{}`) | `200` or `204` |
| `GET` | `/calls/{id}/participants` | List participants | — | `200`: `{ "data": [ { "user_id", "user_name", "avatar_url", "role", "joined_at", "left_at", "hand_raised_at"? } ] }` |
| `POST` | `/calls/{id}/reactions` | Send reaction | `{ "emoji": "👍" }` | `200` or `202` |
| `POST` | `/calls/{id}/raise-hand` | Raise/lower hand | `{ "raised": true \| false }` | `200` or `202` |
| `POST` | `/calls/{id}/missed-call-voice-message` | Upload voice after missed call | **Multipart:** `voice` (file), optional `duration_seconds` (form) | `201`: `{ "message_id", "conversation_id", "call_id", "attachment_url", "duration_seconds", "created_at" }` |

**Notes:**

- **Create call:** Create session, insert participants, broadcast `CallIncoming` on WebSocket, send FCM to callee(s). Return `ice_servers` in the response.
- **Signaling:** Forward offer/answer/ice_candidate to other participants on `private-call.{call_id}`; do not store SDP/ICE.
- **Call log:** Include `call_id` and `other_party` (at least `id`; prefer `first_name`, `last_name`, `profile_photo_path` or `avatar_url`) so the app can show "Leave voice message" for missed calls.
- **Missed-call voice message:** Allowed only when call status is `no_answer` and authenticated user is the **caller**. See [04-missed-call-messaging.md](backend-requirements/04-missed-call-messaging.md).

### 3.2 Scheduled calls

| Method | Path | Purpose | Request / Query | Response |
|--------|------|---------|-----------------|----------|
| `POST` | `/scheduled-calls` | Create | `{ "scheduled_at": "ISO8601", "type": "voice" \| "video", "invitee_ids": [int], "title"?: string }` | `201`: `{ "id", "scheduled_at", "type", "title", "invitees", "created_at" }` |
| `GET` | `/scheduled-calls` | List | `page`, `per_page`, `scope=upcoming` \| `past` | `200`: `{ "data": [ { "id", "scheduled_at", "type", "title", "creator", "invitees", "is_creator", "started_call_id" } ] }` |
| `DELETE` | `/scheduled-calls/{id}` | Cancel | — | `200` or `204` |
| `POST` | `/scheduled-calls/{id}/start` | Start (create real call) | (empty) | `201`: same shape as `POST /calls` (`call_id`, `ice_servers`, `type`, `status`, `created_at`) |

**Start:** Only creator can start. Backend creates real call session, notifies invitees (CallIncoming + FCM), sets `started_call_id`, returns `call_id` + `ice_servers` for creator. See [05-scheduled-calls.md](backend-requirements/05-scheduled-calls.md).

---

## 4. WebSocket (Reverb) — Channel and Events

### 4.1 Channel

- **Name:** `private-call.{call_id}`
  Example: `private-call.550e8400-e29b-41d4-a716-446655440000`
- **Auth:** User may subscribe only if they are a participant of that call.

### 4.2 Server → client events (backend must broadcast)

Broadcast on `private-call.{call_id}`. **Exclude the sender** unless noted.

| Event | When | Payload (JSON) |
|-------|------|----------------|
| `CallIncoming` | After creating call or adding participant | `{ "call_id", "caller_id", "caller_name", "caller_avatar_url", "type": "voice" \| "video", "created_at"?: "ISO8601" }` |
| `CallAccepted` | When callee/participant accepts | `{ "call_id", "accepted_by_user_id", "accepted_at"?, "sdp_answer"?: { "type", "sdp" } }` |
| `CallRejected` | When callee rejects | `{ "call_id", "rejected_by_user_id", "rejected_at"?: "ISO8601" }` |
| `CallEnded` | When call ends | `{ "call_id", "ended_by_user_id", "ended_at"?, "reason"?: "ended_by_user" \| "no_answer" \| "rejected" }` |
| `SignalingOffer` | When forwarding SDP offer | `{ "call_id", "from_user_id", "sdp": { "type": "offer", "sdp": "..." } }` |
| `SignalingAnswer` | When forwarding SDP answer | `{ "call_id", "from_user_id", "sdp": { "type": "answer", "sdp": "..." } }` |
| `SignalingIceCandidate` | When forwarding ICE candidate | `{ "call_id", "from_user_id", "candidate": { "candidate", "sdpMid", "sdpMLineIndex" } }` |
| `CallReaction` | When participant sends reaction | `{ "call_id", "from_user_id", "from_user_name"?, "emoji", "sent_at"?: "ISO8601" }` |
| `RaiseHand` | When participant raises/lowers hand | `{ "call_id", "user_id", "raised": true \| false }` |
| `ParticipantAdded` | When participant added mid-call | `{ "call_id", "user_id", "user_name"?, "user_avatar_url"?, "added_by_user_id"?, "added_at"?: "ISO8601" }` |

Use **snake_case** for all keys (e.g. `caller_avatar_url`, `from_user_id`).

### 4.3 Client → server (signaling)

The app sends SDP/ICE via **REST** only: `POST /api/calls/{id}/signaling` with body:

- `{ "type": "offer", "sdp": { "type": "offer", "sdp": "..." } }`
- `{ "type": "answer", "sdp": { "type": "answer", "sdp": "..." } }`
- `{ "type": "ice_candidate", "candidate": { "candidate": "...", "sdpMid": "...", "sdpMLineIndex": 0 } }`

Forward to other participants as `SignalingOffer` / `SignalingAnswer` / `SignalingIceCandidate`. ICE restart is a new offer; treat as normal offer.

---

## 5. FCM Push for Incoming Calls

The app opens the **incoming-call screen** when it receives an FCM **data** message with:

- **`type`** = **`"call_incoming"`** (exact string; used for routing).

And the following in the same `data` map:

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `call_id` | string | Yes | Call session id. |
| `caller_id` | string or number | Yes | Caller user id. |
| `caller_name` | string | Yes | Display name of caller. |
| `caller_avatar_url` | string | No | URL for caller avatar. |
| `call_type` | string | Yes | **`"voice"`** or **`"video"`** (call kind). The app reads `call_type` for this; do not use `type` for voice/video because `type` is reserved for `"call_incoming"` routing. |

**Example FCM data payload:**

```json
{
  "data": {
    "type": "call_incoming",
    "call_id": "550e8400-e29b-41d4-a716-446655440000",
    "caller_id": "123",
    "caller_name": "John",
    "caller_avatar_url": "https://storage.example.com/users/123/avatar.jpg",
    "call_type": "video"
  }
}
```

**When to send:** After creating a call (1:1 or group) or when adding a participant to an ongoing call. Send to the callee's/invitee's device(s) (FCM tokens for that user). Use high priority and, on Android, a channel suitable for incoming calls (e.g. `incoming_calls`).

---

## 6. Rate Limiting (recommended)

| Action | Limit | Scope |
|--------|-------|--------|
| Create call | 30/min | per user |
| Accept / Reject / End | 60/min | per user |
| Signaling | 200/min | per user, per call_id |
| TURN credentials | 20/min | per user |
| Add participant | 10/min | per user, per call_id |
| Reactions | 20/min | per user, per call_id |
| Raise hand | 30/min | per user, per call_id |
| Missed-call voice message | 5/hour | per user |
| Scheduled call create | 20/day | per user |

Return **429** with a clear message when exceeded.

---

## 7. Reference: Existing Backend Requirements

For schema, validation, and business rules, implement according to:

| Doc | Content |
|-----|---------|
| [01-call-signaling-and-turn.md](backend-requirements/01-call-signaling-and-turn.md) | call_sessions, call_participants, TURN, create/accept/reject/end/signaling, call log |
| [02-group-calls-and-participants.md](backend-requirements/02-group-calls-and-participants.md) | Group create, add participant, leave, ParticipantAdded |
| [03-call-reactions-and-raise-hand.md](backend-requirements/03-call-reactions-and-raise-hand.md) | Reactions, raise hand, broadcast payloads |
| [04-missed-call-messaging.md](backend-requirements/04-missed-call-messaging.md) | Missed-call voice message endpoint and conversation message |
| [05-scheduled-calls.md](backend-requirements/05-scheduled-calls.md) | scheduled_calls schema, CRUD, start, reminders |
| [06-push-notifications-and-reconnect.md](backend-requirements/06-push-notifications-and-reconnect.md) | Push timing, reconnect/ICE restart behavior |

---

## 8. Implementation Checklist (Backend)

- [ ] **Auth:** All call endpoints require `Authorization: Bearer {token}`; channel auth allows only participants for `private-call.{call_id}`.
- [ ] **POST /calls** (1:1 and group): Create session, participants, broadcast `CallIncoming`, send FCM with `type: "call_incoming"` and `call_type`, return `call_id` + `ice_servers`.
- [ ] **POST /calls/{id}/accept**, **reject**, **end**: Implement and broadcast corresponding WebSocket events.
- [ ] **POST /calls/{id}/signaling**: Accept offer/answer/ice_candidate; broadcast to other participants (exclude sender).
- [ ] **GET /calls/turn-credentials**: Return `ice_servers`.
- [ ] **GET /calls**: Paginated call log with `call_id`, `other_party`, `direction`, etc.
- [ ] **POST /calls/{id}/participants**, **leave**, **GET /calls/{id}/participants**: Implement and broadcast `ParticipantAdded` when adding.
- [ ] **POST /calls/{id}/reactions**, **raise-hand**: Implement and broadcast `CallReaction`, `RaiseHand`.
- [ ] **POST /calls/{id}/missed-call-voice-message**: Multipart upload; create conversation message; return 201 with message_id, conversation_id, attachment_url, duration_seconds.
- [ ] **Scheduled calls:** POST/GET/DELETE `/scheduled-calls`, POST `/scheduled-calls/{id}/start`; reminders (cron) per doc 05.
- [ ] **FCM:** On new call (or add participant), send data message with `type: "call_incoming"`, `call_id`, `caller_id`, `caller_name`, `caller_avatar_url`, `call_type`: `"voice"` or `"video"`.
- [ ] **Reconnect:** No special API; client sends new offer (ICE restart); backend forwards as normal `SignalingOffer`.

---

*Back to [README.md](README.md) | [implementation-plan.md](implementation-plan.md) | [backend-requirements/](backend-requirements/README.md)*
