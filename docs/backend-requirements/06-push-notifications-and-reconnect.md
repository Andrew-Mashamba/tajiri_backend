# Backend Requirements: Push Notifications and Reconnect

**Features:** Incoming call push notifications, CC-3 (Reconnect / re-signaling support), rate limits  
**Phase:** 5  
**Depends on:** [01-call-signaling-and-turn.md](01-call-signaling-and-turn.md)

---

## 1. Push notifications for incoming calls

### 1.1 Goal

When the backend creates a call (or adds a user to an ongoing call), the **callee** may not have the app in the foreground or may not be connected to WebSocket. Backend must send a **push notification** (FCM for Android, APNs for iOS) so the device can show a native “Incoming call” UI (full-screen or banner) and, when the user taps accept, open the app and complete the accept flow.

### 1.2 Prerequisites

- **Device tokens:** Backend must store per user (and per device) a push token for FCM and/or APNs. Typically:
  - Table: `user_devices` or `push_tokens`: user_id, device_id (optional), platform (ios|android), token (string), updated_at.
  - Clients register the token after login (e.g. `POST /api/devices` or `POST /api/users/me/push-token`).
- **FCM/APNs:** Laravel uses a package (e.g. `laravel-firebase`) or HTTP API to send FCM messages; for APNs use `laravel-apn` or similar. Configure server keys / certificates in `.env`.

### 1.3 When to send push

- **Create call (1:1):** After creating the call session and broadcasting `CallIncoming` via WebSocket, also send a push to the **callee** (all their devices or only the most recent).
- **Add participant (group):** When adding a user to an ongoing call, send push to that user with payload indicating “incoming call” or “added to ongoing call” so they can join.

### 1.4 Payload (FCM example)

- **Title:** “Incoming call” or caller’s name (e.g. “John”).
- **Body:** “Voice call” or “Video call”.
- **Data payload (for client handling):**
  - `type`: `incoming_call` (so Flutter can open call UI, not generic notification).
  - `call_id`: UUID of the call (so app can load call and show accept/reject).
  - `caller_id`: user id.
  - `caller_name`: display name.
  - `call_type`: `voice` | `video`.
  - `ongoing`: optional boolean (true when added to existing group call).

Example (FCM data message):

```json
{
  "data": {
    "type": "incoming_call",
    "call_id": "550e8400-e29b-41d4-a716-446655440000",
    "caller_id": "123",
    "caller_name": "John",
    "call_type": "video",
    "ongoing": "false"
  },
  "notification": {
    "title": "Incoming video call",
    "body": "John"
  },
  "android": {
    "priority": "high",
    "channel_id": "incoming_calls"
  },
  "apns": {
    "payload": {
      "aps": {
        "sound": "default",
        "content-available": 1
      }
    }
  }
}
```

- Use high priority and a dedicated channel (Android) so the device can show a full-screen incoming call UI if the app implements it.
- **iOS:** You may need VoIP push or CallKit payload for true “incoming call” experience; document the exact APNs payload for your client team.

### 1.5 Backend implementation

- In the same place where you broadcast `CallIncoming` (e.g. after `POST /api/calls` or when adding participant), call a service method e.g. `PushNotificationService::sendIncomingCallNotification(CalleeUser $user, CallSession $call)`.
- Load push tokens for that user (prefer recent tokens); send one FCM/APNs request per token (or use FCM topic per user if you use topics).
- Do not block the HTTP response on push; optionally queue a job `SendIncomingCallPush` so response returns quickly.

### 1.6 Idempotency and expiry

- If the call is rejected or ends before the push is sent, you can skip sending or send a “call ended” data-only notification so the client dismisses the incoming UI.
- Push should be sent only for calls in status `pending`/`ringing`; if call already ended, do not send “incoming call” push.

---

## 2. Reconnect / re-signaling (CC-3)

### 2.1 Goal

When a participant’s network drops temporarily, the WebRTC connection may go to “disconnected” or “failed”. The client will try **ICE restart**: create a new offer with `iceRestart: true` and send it via signaling. Backend must allow this without treating it as a new call or rejecting it.

### 2.2 Requirements

- **Same call, same user:** The user re-sending an offer is the same participant who was already in the call. Backend must not require a new “accept” from the other side; just forward the new offer (and subsequent ICE candidates) as usual.
- **Signaling endpoint:** `POST /api/calls/{id}/signaling` (doc 01) already accepts offer/answer/ICE. No change needed other than to ensure:
  - Call status can still be `connected` when this request arrives.
  - Rate limit for signaling (e.g. 100–200/min per user per call) allows a burst of ICE candidates during reconnection.
- **WebSocket:** If the client reconnects to WebSocket and re-subscribes to `private-call.{call_id}`, channel authorization must still allow them (they are still in `call_participants` with joined_at set). No extra backend logic beyond existing channel auth.
- **No duplicate “CallEnded”:** Ensure that a brief disconnect does not trigger backend to think the call ended. Call ends only when someone explicitly sends `POST /api/calls/{id}/end` or `leave`, or when ring timeout expires.

### 2.3 Optional: explicit “reconnect” event

- You do not need a special “reconnect” API. The client just sends a new offer (type `offer`) with `iceRestart`; backend forwards it as `SignalingOffer`. If you want to log reconnects for analytics, you can add a request body field `ice_restart: true` and log it server-side without changing behavior.

---

## 3. Rate limiting (consolidated)

Apply these limits in Laravel (e.g. `ThrottleRequests` or custom middleware).

| Action | Limit | Scope | HTTP response |
|--------|-------|--------|----------------|
| Create call | 30 requests | per user, per minute | 429 |
| Accept / Reject / End | 60 requests | per user, per minute | 429 |
| Signaling (offer/answer/ICE) | 200 requests | per user, per call_id, per minute | 429 |
| TURN credentials | 20 requests | per user, per minute | 429 |
| Add participant | 10 requests | per user, per call_id, per minute | 429 |
| Reactions | 20 requests | per user, per call_id, per minute | 429 |
| Raise hand | 30 requests | per user, per call_id, per minute | 429 |
| Missed-call voice message | 5 requests | per user, per hour | 429 |
| Scheduled call create | 20 requests | per user, per day | 429 |

Return `429 Too Many Requests` with header `Retry-After: 60` (seconds). Body: `{ "message": "Too many attempts. Please try again later." }`.

---

## 4. Security reminders

- **Push token storage:** Store tokens securely; do not log them. Invalidate tokens on logout.
- **Call payload in push:** Do not put sensitive data in push; call_id and caller name are acceptable. Encryption keys or SDP must never be in push.
- **Re-signaling:** Same authorization as doc 01 — only participants can send signaling; validate on every request.

---

## 5. Summary checklist for backend

- [ ] Store and update push tokens per user/device (FCM, APNs).
- [ ] On call create (and on add participant), send incoming-call push to callee with call_id, caller_name, call_type.
- [ ] Use high-priority / CallKit-compatible payload so device can show incoming call UI.
- [ ] Optional: queue push in a job so API response is fast.
- [ ] Allow re-signaling (offer/answer/ICE) for calls in status `connected`; no special “reconnect” endpoint.
- [ ] Apply rate limits as above; return 429 with Retry-After.
