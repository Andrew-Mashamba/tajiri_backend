# Backend Requirements: Group Calls and Participants

**Features:** VC-5, VD-6 (Add participants), GC-1, GC-2, GC-4, GC-6 (Group voice/video, participant selection, grid, add/remove mid-call)  
**Phase:** 2  
**Depends on:** [01-call-signaling-and-turn.md](01-call-signaling-and-turn.md) (call_sessions, call_participants, WebSocket channels)

---

## 1. Authorization rules for group calls

- **Create group call:** Authenticated user can invite only users they are allowed to call (e.g. contacts or members of the same group).
- **Participant list:** When creating from a group, `invited_user_ids` MUST be a subset of that group’s members. Backend MUST validate group membership.
- **Add participant mid-call:** Only an existing participant in the call can add someone. The user being added must be callable by the inviter (same relationship rules).
- **Leave / remove:** Any participant can leave themselves; only caller (or a “host” role) can remove another participant, if you support “remove” (otherwise only “leave” is required).

---

## 2. Database schema (additions / changes)

### 2.1 Table: `call_sessions` (extend from doc 01)

Ensure these columns exist for group calls:

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `group_id` | `foreignId` → groups.id | Yes | Set when call is started from a group. Null for 1:1. |
| `max_participants` | `unsignedTinyInteger` | Yes | Default 32. Cap for group size. |

- For 1:1, `callee_id` is set and `group_id` is null. For group, `callee_id` can be null and `group_id` set (and/or you still store “initial callee” for UX).

### 2.2 Table: `call_participants` (extend from doc 01)

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `invited_at` | `timestamp` | Yes | When this user was invited (for group: when call was created or when added mid-call). |
| `role` | `enum('caller','callee','participant')` | No | For group: one caller, rest participant (or callee for first invitee). |

- **Invited but not joined:** For group, insert rows for each invited user with `joined_at` = null, `invited_at` = now. When they accept, set `joined_at` = now.

### 2.3 Optional: `call_invitations` (alternative model)

If you prefer to separate “invitation” from “participant”:

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | `bigInteger` (PK) | No | |
| `call_session_id` | `foreignId` | No | |
| `user_id` | `foreignId` | No | Invited user. |
| `invited_by_user_id` | `foreignId` | No | Who added them (caller or participant). |
| `invited_at` | `timestamp` | No | |
| `status` | `enum('pending','accepted','rejected')` | No | |

Then when they accept, you create/update `call_participants`. For simplicity, the rest of this doc assumes a single `call_participants` table with `invited_at` and `joined_at`.

---

## 3. Create group call (or 1:1 with multiple invitees)

### 3.1 Endpoint

**Endpoint:** `POST /api/calls`

**Request body (group call):**

```json
{
  "type": "video",
  "group_id": 789,
  "invited_user_ids": [456, 457, 458]
}
```

**Request body (1:1 — unchanged from doc 01):**

```json
{
  "callee_id": 456,
  "type": "voice"
}
```

| Field | Type | Required | Rules | Description |
|-------|------|----------|--------|-------------|
| `callee_id` | integer | Required for 1:1 | exists:users,id; not caller | Single callee for 1:1. |
| `group_id` | integer | Required for group | exists:groups,id; user is member | Group from which to invite. |
| `invited_user_ids` | array of integers | Required for group | array; each exists:users,id; each must be member of group_id; max 31 (32 - caller) | Users to invite. |
| `type` | string | Yes | in:voice,video | |

**Validation:**

- If `group_id` present: authenticated user must be member of that group; every id in `invited_user_ids` must be in that group; no duplicate; max participants total 32 (including caller).
- If `callee_id` present: same as doc 01 (1:1).

**Business logic:**

1. Create `call_sessions`: caller_id = auth user, callee_id = null for group (or first invitee if you want), group_id = request group_id, type, status = `pending`, max_participants = 32.
2. Insert `call_participants`: one row for caller (role=caller, joined_at=now, invited_at=now); one row per invited user (role=participant, joined_at=null, invited_at=now).
3. For each invited user, broadcast `CallIncoming` on channel `private-call.{call_id}`. Only invited users (and caller) can subscribe to this channel — authorize in channel auth by checking `call_participants` for this call_id and user_id.
4. Return same shape as doc 01 § 4.1, plus list of invited participants if desired:

```json
{
  "call_id": "uuid",
  "status": "pending",
  "type": "video",
  "group_id": 789,
  "participant_ids": [456, 457, 458],
  "ice_servers": [ ... ],
  "created_at": "ISO8601"
}
```

**Errors:** 400 (validation), 403 (not group member or cannot call), 404 (group/user not found), 429 (rate limit).

---

## 4. Accept call (group — callee or invited participant)

- Same endpoint as doc 01: `POST /api/calls/{id}/accept`.
- **Authorization:** User must be in `call_participants` for this call with `joined_at` = null (i.e. invited). So: callee for 1:1, or any invited_user for group.
- **Business logic:** Same as doc 01: set status = `connected` (if first acceptor), set `joined_at` = now for this user, broadcast `CallAccepted` to caller and all already-joined participants. Optionally include `sdp_answer` in request and forward to others.

---

## 5. Add participant mid-call

### 5.1 Endpoint

**Endpoint:** `POST /api/calls/{id}/participants`

**Request body:**

```json
{
  "user_id": 459
}
```

| Field | Type | Required | Rules | Description |
|-------|------|----------|--------|-------------|
| `user_id` | integer | Yes | exists:users,id; not already in call; callable by inviter | User to add. |

**Validation:**

- Call must exist and status = `connected` (or ringing).
- Authenticated user must be a participant with `joined_at` not null (already in the call).
- Total participants after add must be ≤ max_participants (e.g. 32).
- `user_id` must not already have a row in `call_participants` for this call (or status must not be accepted).
- Backend MUST check “can the current user call user_id?” (contact or same group).

**Business logic:**

1. Insert into `call_participants`: user_id = request user_id, role = participant, invited_at = now, joined_at = null.
2. Notify the new user: broadcast `CallIncoming` to them. Payload should indicate “you are being added to an ongoing call” (e.g. `"ongoing": true`) so client can show “Join” instead of “Answer” and optionally pre-connect.
3. Broadcast `ParticipantAdded` to all existing participants (those with joined_at not null):

```json
{
  "call_id": "uuid",
  "user_id": 459,
  "user_name": "Alice",
  "avatar_url": "...",
  "added_by_user_id": 123,
  "added_at": "ISO8601"
}
```

**Response:** `201 Created`

```json
{
  "call_id": "uuid",
  "participant": {
    "user_id": 459,
    "user_name": "Alice",
    "avatar_url": "...",
    "role": "participant",
    "invited_at": "ISO8601"
  }
}
```

**Errors:** 400, 403, 404, 422 (already in call, max participants reached), 429.

---

## 6. Leave call (participant leaves themselves)

### 6.1 Endpoint

**Endpoint:** `POST /api/calls/{id}/leave`

**Request body:** Empty or `{}`.

**Validation:** Call exists; user is a participant.

**Business logic:**

1. Update `call_participants`: set left_at = now for this user.
2. If this was the last participant leaving (or only caller left), update `call_sessions`: status = `ended`, ended_at = now, duration_seconds = (ended_at - started_at). Broadcast `CallEnded` to all (including the one who left).
3. If others remain, broadcast `ParticipantLeft` to remaining participants:

```json
{
  "call_id": "uuid",
  "user_id": 123,
  "left_at": "ISO8601"
}
```

**Response:** `200 OK`

```json
{
  "call_id": "uuid",
  "status": "ended",
  "left_at": "ISO8601"
}
```

- If call is still ongoing for others, status in response can be `left` or `ended` depending on whether call continues.

**Errors:** 403, 404.

---

## 7. Remove participant (optional — host removes another user)

### 7.1 Endpoint

**Endpoint:** `DELETE /api/calls/{id}/participants/{participant_id}` or `POST /api/calls/{id}/participants/{participant_id}/remove`

**Authorization:** Only caller (or a designated “host”) can remove. Removed user must be a current participant (joined_at not null, left_at null).

**Business logic:**

1. Set `left_at` = now for that participant.
2. Broadcast `ParticipantRemoved` to all (including removed user) so removed user’s client can disconnect:

```json
{
  "call_id": "uuid",
  "user_id": 456,
  "removed_by_user_id": 123,
  "removed_at": "ISO8601"
}
```

**Response:** `200 OK`. Errors: 403, 404, 422.

---

## 8. Get call participants (for UI / speaker list)

### 8.1 Endpoint

**Endpoint:** `GET /api/calls/{id}/participants`

**Query:** Optional `?joined_only=1` to list only users who have joined (joined_at not null, left_at null).

**Authorization:** User must be a participant of the call.

**Response:** `200 OK`

```json
{
  "data": [
    {
      "user_id": 123,
      "user_name": "John",
      "avatar_url": "...",
      "role": "caller",
      "joined_at": "ISO8601",
      "left_at": null,
      "is_active": true
    },
    {
      "user_id": 456,
      "user_name": "Jane",
      "avatar_url": "...",
      "role": "participant",
      "joined_at": "ISO8601",
      "left_at": null,
      "is_active": true
    }
  ]
}
```

- Useful for group call UI to show grid and “who is in the call”. Optionally include `raised_hand` from doc 03 if implemented.

---

## 9. WebSocket events (summary for group)

| Event | Direction | When |
|-------|-----------|------|
| `CallIncoming` | Server → client | To each invited user (create or add participant). Include `ongoing: true` when adding to existing call. |
| `CallAccepted` | Server → client | To caller and existing participants when someone accepts. |
| `CallRejected` | Server → client | To caller (and optionally to inviter when an added user rejects). |
| `CallEnded` | Server → client | To all when call ends. |
| `ParticipantAdded` | Server → client | To existing participants when someone is added. |
| `ParticipantLeft` | Server → client | To remaining participants when someone leaves. |
| `ParticipantRemoved` | Server → client | To all when host removes a participant. |
| `SignalingOffer` / `SignalingAnswer` / `SignalingIceCandidate` | Server → client | Forward to other participants; for group you may need to forward to “all others” or use SFU (see § 10). |

### 9.1 Channel authorization (group)

- For `private-call.{call_id}`, allow subscription if the user has a row in `call_participants` for this call (whether invited or already joined). This way newly added users can subscribe after receiving `CallIncoming`.

---

## 10. SFU (Selective Forwarding Unit) for group media

For 3+ participants, media is usually sent through an SFU instead of mesh P2P. Backend responsibilities:

### 10.1 Create SFU room

- When call becomes “connected” with 2+ participants (or when first group call is created), backend (or a separate SFU service) creates a “room” on your SFU (e.g. mediasoup, Janus).
- Store `sfu_room_id` and `sfu_url` (WebSocket URL for clients) on `call_sessions` or in a separate `call_sfu_rooms` table.

### 10.2 Expose SFU info to clients

- In `POST /api/calls/{id}/accept` response (and in create response for group), include:

```json
{
  "sfu_room_id": "room_uuid",
  "sfu_url": "wss://sfu.example.com/ws",
  "sfu_token": "jwt_or_opaque_token"
}
```

- Client uses this to connect to SFU and join the room; media flows client ↔ SFU ↔ clients. Signaling (SDP/ICE) may be between client and SFU only; Laravel only needs to hand out the room and token.

### 10.3 Token generation (if SFU requires auth)

- Backend generates a short-lived JWT or token that encodes: user_id, call_id, room_id, expiry. SFU validates the token and allows join. Document the exact claims and secret for your SFU.

### 10.4 End of call

- When call ends, backend notifies SFU to close the room (optional; or SFU closes when last participant disconnects).

---

## 11. Rate limiting (group)

- **Add participant:** 10 adds per call per user per minute (avoid spam).
- **Create call (group):** Same as doc 01 create (e.g. 30/min per user).

---

## 12. Summary checklist for backend

- [ ] Extend `call_sessions` with group_id, max_participants.
- [ ] Extend `call_participants` with invited_at; support multiple rows per call.
- [ ] Channel auth: allow any user in `call_participants` to subscribe to `private-call.{call_id}`.
- [ ] `POST /api/calls` with group_id + invited_user_ids; create session and participant rows; broadcast CallIncoming to each invitee.
- [ ] `POST /api/calls/{id}/accept` for group (any invited user).
- [ ] `POST /api/calls/{id}/participants` (add participant); broadcast CallIncoming to new user, ParticipantAdded to others.
- [ ] `POST /api/calls/{id}/leave`; broadcast ParticipantLeft or CallEnded.
- [ ] Optional: DELETE participants (remove); broadcast ParticipantRemoved.
- [ ] `GET /api/calls/{id}/participants`.
- [ ] Optional: SFU room creation and token endpoint; return sfu_room_id, sfu_url, sfu_token in accept/create response.
