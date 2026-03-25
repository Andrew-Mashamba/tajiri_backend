# Backend Requirements: Call Reactions and Raise Hand

**Features:** VC-6, VD-9 (Call reactions), GC-5 (Raise hand)  
**Phase:** 4  
**Depends on:** [01-call-signaling-and-turn.md](01-call-signaling-and-turn.md), [02-group-calls-and-participants.md](02-group-calls-and-participants.md) (active call, WebSocket channel)

---

## 1. Overview

- **Reactions:** User sends an emoji (e.g. 👍, ❤️) during a call; backend broadcasts to other participants so they can show a short animation. No persistence required.
- **Raise hand:** User toggles “hand raised”; backend broadcasts state to others. Optionally persist so late joiners see who has hand raised.

---

## 2. Call reactions

### 2.1 Client → server (how backend receives)

**Option A — WebSocket (recommended):** Client sends a message to the server (e.g. via a dedicated “client event” or HTTP endpoint) that is then broadcast to other participants. Laravel Reverb: you can use a custom event that the client triggers via HTTP so the server broadcasts it.

**Option B — REST:** Client POSTs to an endpoint; server broadcasts to others.

### 2.2 Endpoint (if using REST)

**Endpoint:** `POST /api/calls/{id}/reactions`

**Request body:**

```json
{
  "emoji": "👍"
}
```

| Field | Type | Required | Rules | Description |
|-------|------|----------|--------|-------------|
| `emoji` | string | Yes | string; max 10 chars; allow only allowed emoji list | Single emoji or short string (e.g. "👍", "❤️"). |

**Validation:**

- Call exists; status = `connected`.
- User is a participant (joined_at not null, left_at null).
- Optional: restrict to a list of allowed emojis (e.g. 👍, ❤️, 🙌, 😂, 👏) to avoid abuse.
- **Rate limit:** 20 reactions per user per minute per call. Return 429 if exceeded.

**Business logic:**

1. Do not store the reaction in the database (ephemeral).
2. Broadcast to all other participants on channel `private-call.{call_id}` (exclude sender).

**Server → client event:** `CallReaction`

**Payload:**

```json
{
  "call_id": "uuid",
  "from_user_id": 123,
  "from_user_name": "John",
  "emoji": "👍",
  "sent_at": "2025-02-16T10:05:00.000000Z"
}
```

**Response:** `202 Accepted`

```json
{
  "ok": true
}
```

**Errors:** 400 (invalid emoji), 403, 404, 422 (call not connected), 429 (rate limit).

### 2.3 If using WebSocket for client → server

- If your stack allows clients to emit events that the server listens to (e.g. Reverb client events), client can emit `SendReaction` with `{ "emoji": "👍" }`. Server validates (same rules as above), then broadcasts `CallReaction` to others. No REST endpoint needed.

---

## 3. Raise hand

### 3.1 State

- Each participant can have “hand raised” or “hand down”. Backend broadcasts state changes so all participants (and late joiners, if you persist) see who has hand raised.

### 3.2 Persistence (optional but recommended)

- Store in `call_participants`: add column `hand_raised_at` (timestamp, nullable). When user raises hand, set to now; when they lower, set to null. This allows new joiners to see current state via `GET /api/calls/{id}/participants`.

### 3.3 Endpoint

**Endpoint:** `POST /api/calls/{id}/raise-hand` or `PUT /api/calls/{id}/my-state`

**Request body:**

```json
{
  "raised": true
}
```

| Field | Type | Required | Rules | Description |
|-------|------|----------|--------|-------------|
| `raised` | boolean | Yes | | true = raise, false = lower. |

**Validation:**

- Call exists; status = `connected`.
- User is a participant.

**Business logic:**

1. If persisting: update `call_participants` set hand_raised_at = now() when raised = true, or null when false.
2. Broadcast to all participants on `private-call.{call_id}` (include sender so they get confirmation if needed).

**Server → client event:** `RaiseHand`

**Payload:**

```json
{
  "call_id": "uuid",
  "user_id": 123,
  "user_name": "John",
  "raised": true,
  "at": "2025-02-16T10:05:00.000000Z"
}
```

- When `raised` is false, same event with `raised: false` so clients can remove the hand icon.

**Response:** `200 OK`

```json
{
  "raised": true,
  "at": "2025-02-16T10:05:00.000000Z"
}
```

**Errors:** 403, 404, 422.

### 3.4 GET participants (include hand state)

- In [02-group-calls-and-participants.md](02-group-calls-and-participants.md) § 8, response for each participant should include:

```json
{
  "user_id": 123,
  "user_name": "John",
  "hand_raised": true,
  "hand_raised_at": "2025-02-16T10:05:00.000000Z"
}
```

- So when a user joins a group call, they can fetch participants and show who already has hand raised.

### 3.5 Database (optional)

**Table:** `call_participants` — add column:

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `hand_raised_at` | `timestamp` | Yes | Set when user raises hand; null when lowered. |

---

## 4. Rate limiting summary

| Action | Limit | Response |
|--------|-------|----------|
| Reactions | 20 per user per call per minute | 429 |
| Raise hand | 30 per user per call per minute (toggle spam) | 429 |

---

## 5. Summary checklist for backend

- [ ] `POST /api/calls/{id}/reactions` with body `{ "emoji": "..." }`; validate; broadcast `CallReaction` to other participants; rate limit.
- [ ] `POST /api/calls/{id}/raise-hand` with body `{ "raised": true|false }`; optionally update `call_participants.hand_raised_at`; broadcast `RaiseHand` to all.
- [ ] Include `hand_raised` / `hand_raised_at` in `GET /api/calls/{id}/participants` response.
- [ ] Optional: migration to add `hand_raised_at` to `call_participants`.
