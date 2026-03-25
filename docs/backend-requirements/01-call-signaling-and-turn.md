# Backend Requirements: Call Signaling and TURN (1:1 Calls)

**Features:** CC-1 (Call signaling), CC-2 (NAT traversal / TURN), CC-5 (Call log), VC-1 (1:1 voice), VD-1 (1:1 video)  
**Phase:** 0, 1

---

## 1. Authentication and authorization

- **All endpoints** in this document require the user to be authenticated (e.g. Laravel Sanctum: `auth:sanctum` middleware).
- **Caller–callee relationship:** Before creating a call, backend MUST verify that the authenticated user is allowed to call the target user(s). Implement one of:
  - Both users are in each other’s contacts, or
  - Both users share at least one group/chat (if calling from group), or
  - Your product rule (e.g. “any registered user can call any other”).
- **Call participation:** For any action on `call_id`, the authenticated user MUST be the caller, the callee, or an invited/joined participant. Otherwise return `403 Forbidden`.

---

## 2. Database schema

### 2.1 Table: `call_sessions`

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | `uuid` or `bigInteger` (PK) | No | Primary key. Use UUID for public API if desired. |
| `caller_id` | `foreignId` → users.id | No | User who initiated the call. |
| `callee_id` | `foreignId` → users.id | Yes | For 1:1: the single callee. Null for group-originated. |
| `group_id` | `foreignId` → groups.id | Yes | If call was started from a group, reference. Null for 1:1. |
| `type` | `enum('voice','video')` | No | Call type. |
| `status` | `enum('pending','ringing','connected','ended','rejected','no_answer')` | No | Current state. |
| `started_at` | `timestamp` | Yes | When call became connected (first participant connected). |
| `ended_at` | `timestamp` | Yes | When call ended. |
| `duration_seconds` | `unsignedInteger` | Yes | Computed: difference between ended_at and started_at. |
| `created_at` | `timestamp` | No | |
| `updated_at` | `timestamp` | No | |

**Indexes:**

- `call_sessions(caller_id, created_at)` for “my outgoing calls”.
- `call_sessions(callee_id, created_at)` for “my incoming calls”.
- `call_sessions(status)` for active calls (optional).
- `call_sessions(created_at)` for listing and cleanup.

**Business rules:**

- For 1:1, exactly one of `callee_id` or group-based invite applies; for group call, see document 02.
- `duration_seconds` is set when status becomes `ended` (or in a job that runs on end).

### 2.2 Table: `call_participants` (used in Phase 1 for consistency; required for Phase 2)

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | `bigInteger` (PK) | No | |
| `call_session_id` | `foreignId` → call_sessions.id | No | |
| `user_id` | `foreignId` → users.id | No | |
| `role` | `enum('caller','callee','participant')` | No | For 1:1: caller + callee. |
| `joined_at` | `timestamp` | Yes | When user joined (for group: when they accepted). |
| `left_at` | `timestamp` | Yes | When user left or call ended. |
| `created_at` | `timestamp` | No | |
| `updated_at` | `timestamp` | No | |

**Indexes:**

- `call_participants(call_session_id)`
- `call_participants(user_id, joined_at)` for “calls I participated in”.

**For 1:1 calls:** When creating a call, insert two rows: one for caller (role=caller, joined_at=now), one for callee (role=callee, joined_at=null until accept).

---

## 3. WebSocket (Laravel Reverb / Echo)

### 3.1 Channel name

- **Private channel:** `private-call.{call_id}`  
  Example: `private-call.550e8400-e29b-41d4-a716-446655440000`

### 3.2 Channel authorization (backend)

- In `routes/channels.php` (or Reverb channel auth), authorize subscription only if the authenticated user is a participant of the call.
- Implementation: load `CallSession` by `call_id`, then check that the user is caller, callee, or exists in `call_participants` for this session. Return `true` to allow, `false` to deny.

```php
// Example (pseudo)
Broadcast::channel('call.{callId}', function ($user, $callId) {
    return CallSession::userIsParticipant($callId, $user->id);
});
```

- Use channel name `call.{callId}` if your convention is `call.` prefix; ensure Flutter subscribes to the same name.

### 3.3 Server → client events (broadcast by backend)

| Event name | When to broadcast | Payload (JSON) | Recipients |
|------------|-------------------|----------------|------------|
| `CallIncoming` | After call is created and callee(s) must be notified | `{ "call_id": "uuid", "caller_id": 123, "caller_name": "John", "caller_avatar_url": "...", "type": "voice" \| "video", "created_at": "ISO8601" }` | Callee(s) only (exclude caller) |
| `CallAccepted` | When callee accepts | `{ "call_id": "uuid", "accepted_by_user_id": 456, "accepted_at": "ISO8601", "sdp_answer": "..." }` (optional: SDP in same event) | Caller (and others in group) |
| `CallRejected` | When callee rejects | `{ "call_id": "uuid", "rejected_by_user_id": 456, "rejected_at": "ISO8601" }` | Caller |
| `CallEnded` | When any participant ends the call | `{ "call_id": "uuid", "ended_by_user_id": 123, "ended_at": "ISO8601", "reason": "ended_by_user" \| "no_answer" \| "rejected" }` | All participants |
| `SignalingOffer` | When backend forwards an SDP offer | `{ "call_id": "uuid", "from_user_id": 123, "sdp": { "type": "offer", "sdp": "..." } }` | Other participant(s) |
| `SignalingAnswer` | When backend forwards an SDP answer | `{ "call_id": "uuid", "from_user_id": 456, "sdp": { "type": "answer", "sdp": "..." } }` | Other participant(s) |
| `SignalingIceCandidate` | When backend forwards an ICE candidate | `{ "call_id": "uuid", "from_user_id": 123, "candidate": { "candidate": "...", "sdpMid": "...", "sdpMLineIndex": 0 } }` | Other participant(s) |

- **Exclude sender:** When broadcasting, do not send the event to the user who triggered it (e.g. when A sends offer, only B receives `SignalingOffer`).

### 3.4 Client → server (how backend receives signaling)

- **Option A (recommended):** Client sends SDP/ICE via WebSocket to a server listener that validates and re-broadcasts to other participants on `private-call.{call_id}`. You need a custom Reverb event or HTTP endpoint that pushes to the channel.
- **Option B:** Client sends SDP/ICE via REST `POST /api/calls/{id}/signaling` (see below). Backend stores nothing (or only in-memory for forwarding), and broadcasts to other participants.

---

## 4. REST API — Detailed specification

### 4.1 Create call

**Endpoint:** `POST /api/calls`

**Request headers:**

- `Authorization: Bearer {token}` or `Cookie` for Sanctum
- `Content-Type: application/json`

**Request body:**

```json
{
  "callee_id": 456,
  "type": "voice"
}
```

| Field | Type | Required | Rules | Description |
|-------|------|----------|--------|-------------|
| `callee_id` | integer | Yes (for 1:1) | exists:users,id; not same as caller | User ID to call. |
| `type` | string | Yes | in:voice,video | Call type. |

**Validation:**

- `callee_id` must exist and must not equal `request()->user()->id`.
- Enforce “can caller call callee?” (contacts / group membership). If not allowed, return `403` with message e.g. "You cannot call this user."

**Business logic:**

1. Create row in `call_sessions`: caller_id = auth id, callee_id = request callee_id, type = request type, status = `pending`.
2. Create two rows in `call_participants`: caller (joined_at = now), callee (joined_at = null).
3. Broadcast `CallIncoming` to callee (on channel `private-call.{call_id}`). Callee must be able to receive (WebSocket connected or push — see doc 06).
4. Optionally set a ring timeout (e.g. 45 s): if no accept/reject, set status to `no_answer` and broadcast `CallEnded` with reason `no_answer`.

**Response:** `201 Created`

```json
{
  "call_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "pending",
  "type": "voice",
  "callee_id": 456,
  "ice_servers": [
    { "urls": "stun:turn.example.com:3478" },
    {
      "urls": "turn:turn.example.com:3478",
      "username": "temporary_user_123_1636123456",
      "credential": "generated_secret"
    }
  ],
  "created_at": "2025-02-16T10:00:00.000000Z"
}
```

- Include `ice_servers` so the client can create `RTCPeerConnection` immediately. Generate TURN credentials (see § 6) and return in same response.

**Errors:**

- `400` — Validation failed (e.g. invalid type).
- `403` — Not allowed to call this user.
- `404` — Callee user not found.
- `429` — Rate limit (e.g. too many calls created in 1 minute).

---

### 4.2 Accept call

**Endpoint:** `POST /api/calls/{id}/accept`

**URL parameters:** `id` = call_session id (or UUID).

**Request headers:** Same as above.

**Request body (optional):**

```json
{
  "sdp_answer": {
    "type": "answer",
    "sdp": "v=0\r\n..."
  }
}
```

- If client sends SDP answer in body, backend forwards it to caller via broadcast `SignalingAnswer` (so caller can set remote description).

**Validation:**

- Call must exist and status must be `pending` or `ringing`.
- Authenticated user must be the callee (for 1:1). For group, user must be in invited list (see doc 02).

**Business logic:**

1. Update `call_sessions`: status = `connected`, started_at = now.
2. Update `call_participants`: set joined_at = now for this user.
3. Broadcast `CallAccepted` to caller (and other participants in group). Include optional `sdp_answer` in payload if provided.
4. If request body had `sdp_answer`, broadcast `SignalingAnswer` to caller (excluding callee).

**Response:** `200 OK`

```json
{
  "call_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "connected",
  "started_at": "2025-02-16T10:00:30.000000Z",
  "ice_servers": [ ... ]
}
```

**Errors:**

- `403` — User is not callee or not allowed.
- `404` — Call not found.
- `422` — Call already accepted, rejected, or ended.

---

### 4.3 Reject call

**Endpoint:** `POST /api/calls/{id}/reject`

**Request body:** Optional `{ "reason": "busy" }` for analytics; not required.

**Validation:** Call exists; status is pending/ringing; user is callee.

**Business logic:**

1. Update `call_sessions`: status = `rejected`, ended_at = now.
2. Broadcast `CallRejected` to caller.
3. Optionally update `call_participants.left_at` for callee.

**Response:** `200 OK`

```json
{
  "call_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "rejected"
}
```

**Errors:** Same as accept (403, 404, 422).

---

### 4.4 End call

**Endpoint:** `POST /api/calls/{id}/end`

**Request body (optional):** `{ "reason": "user_hangup" }`

**Validation:** Call exists; status is `connected` or `ringing` or `pending`; user is a participant (caller or callee).

**Business logic:**

1. Update `call_sessions`: status = `ended`, ended_at = now, duration_seconds = (ended_at - started_at) if started_at set.
2. Update all `call_participants` for this call: set left_at = now where still null.
3. Broadcast `CallEnded` to all participants (excluding the one who ended, if you want; or include so they get one final event).

**Response:** `200 OK`

```json
{
  "call_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "ended",
  "ended_at": "2025-02-16T10:05:00.000000Z",
  "duration_seconds": 270
}
```

**Errors:** 403, 404, 422.

---

### 4.5 Signaling (SDP / ICE)

**Endpoint:** `POST /api/calls/{id}/signaling`

**Request body (one of):**

**Option 1 — SDP offer:**

```json
{
  "type": "offer",
  "sdp": {
    "type": "offer",
    "sdp": "v=0\r\n..."
  }
}
```

**Option 2 — SDP answer:**

```json
{
  "type": "answer",
  "sdp": {
    "type": "answer",
    "sdp": "v=0\r\n..."
  }
}
```

**Option 3 — ICE candidate:**

```json
{
  "type": "ice_candidate",
  "candidate": {
    "candidate": "candidate string",
    "sdpMid": "0",
    "sdpMLineIndex": 0
  }
}
```

**Validation:**

- Call exists; status is `connected` (or `pending`/`ringing` if you allow early signaling); user is participant.
- `type` in `offer`, `answer`, `ice_candidate`; required fields present.
- Do not store SDP/ICE in database; use only for immediate broadcast.

**Business logic:**

1. Broadcast to other participants on `private-call.{call_id}`:
   - If type offer → event `SignalingOffer` with from_user_id and sdp.
   - If type answer → event `SignalingAnswer` with from_user_id and sdp.
   - If type ice_candidate → event `SignalingIceCandidate` with from_user_id and candidate.
2. Exclude the sender from the broadcast.

**Response:** `202 Accepted` (or `200 OK` with empty body).

```json
{}
```

**Rate limit:** Recommended 100–200 signaling requests per call per minute per user. Return `429` if exceeded.

**Errors:** 400 (invalid body), 403, 404, 429.

---

### 4.6 Call log (list)

**Endpoint:** `GET /api/calls` or `GET /api/users/me/calls`

**Query parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `per_page` | int | 20 | 1–100. |
| `page` | int | 1 | |
| `type` | string | (all) | Filter: `voice`, `video`. |
| `direction` | string | (all) | Filter: `incoming`, `outgoing`. |

**Authorization:** Return only calls where the authenticated user is caller or callee (or participant).

**Business logic:**

- Query `call_sessions` joined with `call_participants` (or two queries: as caller, as callee) and optionally filter by type/direction.
- For each call, include: call_id, type, status, other_party (user id + name + avatar), direction (incoming/outgoing), started_at, ended_at, duration_seconds, created_at.
- Order by `created_at` desc.

**Response:** `200 OK`

```json
{
  "data": [
    {
      "call_id": "550e8400-e29b-41d4-a716-446655440000",
      "type": "video",
      "status": "ended",
      "direction": "outgoing",
      "other_party": {
        "id": 456,
        "name": "Jane",
        "avatar_url": "https://..."
      },
      "started_at": "2025-02-16T10:00:30.000000Z",
      "ended_at": "2025-02-16T10:05:00.000000Z",
      "duration_seconds": 270,
      "created_at": "2025-02-16T10:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 50
  }
}
```

---

## 5. TURN credentials

### 5.1 Endpoint

**Endpoint:** `GET /api/calls/turn-credentials`

**Request:** No body. Authenticated user only.

**Response:** `200 OK`

```json
{
  "ice_servers": [
    { "urls": "stun:turn.example.com:3478" },
    {
      "urls": "turn:turn.example.com:3478",
      "username": "temp_123_1636123456",
      "credential": "generated_secret_here"
    }
  ],
  "ttl_seconds": 86400
}
```

### 5.2 Backend implementation

- **STUN:** Return a fixed STUN server URL (your Coturn server or public STUN).
- **TURN:** Use Coturn’s TURN REST API or time-limited secret to generate a username and password valid for a short period (e.g. 1–24 hours). Store no credentials in DB; generate on each request or cache per user for 1 hour.
- **Security:** Credential must be bound to the user (e.g. username contains user id) so only that user can use the TURN allocation. Coturn REST API format: typically username = `timestamp:user_id` and password = HMAC(secret, username).

### 5.3 Example (Coturn REST API)

- Coturn configured with `use-auth-secret` and `lt-cred-mech`; you send to Coturn a REST request to get a temporary username/password, or you compute password = turn(HMAC(shared_secret, username)) and return username (e.g. `expiry_timestamp_userid`) and password.
- Document the exact format for your Coturn version so backend and DevOps align.

---

## 6. Ring timeout (no answer)

- When creating a call, optionally schedule a job (e.g. Laravel job dispatched with delay 45 seconds). Job checks: if call status is still `pending` or `ringing`, set status to `no_answer`, set ended_at = now, and broadcast `CallEnded` with reason `no_answer` to caller (and callee if you want). Cancel the job if call was accepted or rejected before 45 s.

---

## 7. Rate limiting

- **Create call:** 30 requests per minute per user (configurable).
- **Signaling:** 100–200 per call per minute per user (per call_id).
- **TURN credentials:** 20 per minute per user.
- Use Laravel `RateLimiter` or throttle middleware; return `429 Too Many Requests` with `Retry-After` header.

---

## 8. Summary checklist for backend

- [ ] Migrations: `call_sessions`, `call_participants`.
- [ ] Channel auth for `private-call.{call_id}`.
- [ ] `POST /api/calls` (create) + broadcast `CallIncoming` + TURN in response.
- [ ] `POST /api/calls/{id}/accept` + broadcast `CallAccepted`.
- [ ] `POST /api/calls/{id}/reject` + broadcast `CallRejected`.
- [ ] `POST /api/calls/{id}/end` + broadcast `CallEnded`.
- [ ] `POST /api/calls/{id}/signaling` + broadcast offer/answer/ICE.
- [ ] `GET /api/calls/turn-credentials`.
- [ ] `GET /api/calls` (call log with pagination).
- [ ] Caller–callee authorization and rate limits.
- [ ] Optional: ring timeout job.
