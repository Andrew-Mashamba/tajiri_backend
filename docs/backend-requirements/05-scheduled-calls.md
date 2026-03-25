# Backend Requirements: Scheduled Calls

**Feature:** VC-8 (Scheduled calls — schedule a call, send invite, reminders before start)  
**Phase:** 4  
**Depends on:** [01-call-signaling-and-turn.md](01-call-signaling-and-turn.md) (calls, participants), and optionally [02-group-calls-and-participants.md](02-group-calls-and-participants.md) if scheduling group calls.

---

## 1. Overview

- Users can **schedule** a call for a future time and invite one or more participants.
- Backend stores the schedule and sends **reminders** (e.g. push notification or in-app) shortly before the scheduled time (e.g. 5 minutes before).
- At (or after) the scheduled time, the **caller** can “start” the scheduled call, which creates a real call session (same as doc 01) and notifies invitees. So “scheduled call” is a pre-call template; the actual call is created when the scheduler starts it.

---

## 2. Database schema

### 2.1 Table: `scheduled_calls`

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | `uuid` or `bigInteger` (PK) | No | |
| `creator_id` | `foreignId` → users.id | No | User who created the schedule (will be caller). |
| `type` | `enum('voice','video')` | No | Call type. |
| `scheduled_at` | `timestamp` | No | When the call is scheduled (UTC). |
| `title` | `string` | Yes | Optional title (e.g. "Team standup"). |
| `reminder_sent_at` | `timestamp` | Yes | When reminder was sent (e.g. 5 min before). Null until sent. |
| `started_call_id` | `foreignId` → call_sessions.id | Yes | Set when creator starts the call; links to actual call. |
| `created_at` | `timestamp` | No | |
| `updated_at` | `timestamp` | No | |

**Indexes:**

- `scheduled_calls(creator_id, scheduled_at)` for “my upcoming scheduled calls”.
- `scheduled_calls(scheduled_at)` for cron job that sends reminders and cleans old rows.

### 2.2 Table: `scheduled_call_invitees`

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | `bigInteger` (PK) | No | |
| `scheduled_call_id` | `foreignId` → scheduled_calls.id | No | |
| `user_id` | `foreignId` → users.id | No | Invited user. |
| `notified_at` | `timestamp` | Yes | When invite notification was sent. |
| `reminder_sent_at` | `timestamp` | Yes | When reminder was sent to this user (if per-user). |
| `created_at` | `timestamp` | No | |
| `updated_at` | `timestamp` | No | |

**Unique:** (scheduled_call_id, user_id).  
**Indexes:** scheduled_call_invitees(scheduled_call_id), scheduled_call_invitees(user_id).

---

## 3. API specification

### 3.1 Create scheduled call

**Endpoint:** `POST /api/scheduled-calls`

**Request body:**

```json
{
  "scheduled_at": "2025-02-17T14:00:00.000000Z",
  "type": "video",
  "title": "Weekly sync",
  "invitee_ids": [456, 457]
}
```

| Field | Type | Required | Rules | Description |
|-------|------|----------|--------|-------------|
| `scheduled_at` | string (ISO8601) | Yes | date; after now; within 1 year (optional) | When the call is scheduled (UTC). |
| `type` | string | Yes | in:voice,video | |
| `title` | string | No | string; max 255 | Optional title. |
| `invitee_ids` | array of integers | Yes | array; each exists:users,id; max 31 | Users to invite. |

**Validation:**

- `scheduled_at` must be in the future (e.g. at least 5 minutes from now).
- Creator must be allowed to call each invitee (same relationship as doc 01).
- No duplicate invitee_ids; creator cannot be in invitee_ids.

**Business logic:**

1. Insert `scheduled_calls`: creator_id = auth user, type, scheduled_at, title, reminder_sent_at = null, started_call_id = null.
2. Insert one row per invitee in `scheduled_call_invitees` (user_id, notified_at = null).
3. Optional: send “invitation” notification to each invitee (e.g. “John scheduled a video call with you on Feb 17 at 14:00”). If so, set notified_at = now for each.
4. Return created scheduled call with id and invitees.

**Response:** `201 Created`

```json
{
  "id": "550e8400-e29b-41d4-a716-446655440001",
  "scheduled_at": "2025-02-17T14:00:00.000000Z",
  "type": "video",
  "title": "Weekly sync",
  "invitees": [
    { "user_id": 456, "user_name": "Jane", "avatar_url": "..." },
    { "user_id": 457, "user_name": "Bob", "avatar_url": "..." }
  ],
  "created_at": "2025-02-16T10:00:00.000000Z"
}
```

**Errors:** 400, 403, 404, 429.

---

### 3.2 List scheduled calls (upcoming)

**Endpoint:** `GET /api/scheduled-calls`

**Query parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `per_page` | int | 20 | 1–100. |
| `page` | int | 1 | |
| `scope` | string | `upcoming` | `upcoming` (scheduled_at >= now) or `past` (scheduled_at < now). |

**Authorization:** Return only scheduled calls where authenticated user is creator or invitee.

**Business logic:**

- Query `scheduled_calls` joined with `scheduled_call_invitees` or two queries (as creator, as invitee). Filter by scheduled_at vs now for scope. Order by scheduled_at asc for upcoming, desc for past.
- Include: id, scheduled_at, type, title, creator (id, name, avatar), invitees, whether current user is creator, started_call_id (if started).

**Response:** `200 OK`

```json
{
  "data": [
    {
      "id": "uuid",
      "scheduled_at": "2025-02-17T14:00:00.000000Z",
      "type": "video",
      "title": "Weekly sync",
      "creator": { "id": 123, "name": "John", "avatar_url": "..." },
      "invitees": [ ... ],
      "is_creator": false,
      "started_call_id": null
    }
  ],
  "meta": { "current_page": 1, "per_page": 20, "total": 5 }
}
```

---

### 3.3 Get single scheduled call

**Endpoint:** `GET /api/scheduled-calls/{id}`

**Authorization:** User must be creator or invitee.

**Response:** Same shape as one item in list. 404 if not found or not allowed.

---

### 3.4 Update scheduled call (optional)

**Endpoint:** `PUT /api/scheduled-calls/{id}` or `PATCH /api/scheduled-calls/{id}`

**Request body:** Same as create; all fields optional (partial update). Only creator can update.

**Validation:** If rescheduling, scheduled_at must be in future. If changing invitees, validate relationship.

**Response:** 200 OK with updated scheduled call. 403 if not creator, 404 if not found.

---

### 3.5 Cancel scheduled call

**Endpoint:** `DELETE /api/scheduled-calls/{id}`

**Authorization:** Only creator can cancel.

**Business logic:** Soft-delete or set a `cancelled_at` timestamp; exclude from “upcoming” list. Optionally notify invitees that the call was cancelled.

**Response:** `204 No Content` or 200 with `{ "cancelled": true }`.

---

### 3.6 Start scheduled call (create real call)

**Endpoint:** `POST /api/scheduled-calls/{id}/start`

**Purpose:** At (or after) scheduled time, creator starts the call. Backend creates a normal call session (as in doc 01) with the invitees and links it to this scheduled call.

**Authorization:** Only creator; scheduled_at must be <= now (or allow starting up to 15 min before — product decision).

**Business logic:**

1. Load scheduled call; ensure not already started (started_call_id is null) and not cancelled.
2. Create `call_sessions`: caller_id = creator, type = scheduled_call.type, status = pending. For 1:1 with first invitee you could set callee_id; for multiple, use same flow as group call (doc 02): create participants for each invitee.
3. Create `call_participants`: caller + one per invitee (invited_at = now, joined_at = null).
4. Set `scheduled_calls.started_call_id` = new call_sessions.id.
5. Broadcast `CallIncoming` to each invitee (and optionally a “scheduled call starting” push).
6. Return the same structure as `POST /api/calls` (call_id, ice_servers, etc.) so the creator’s client can join the call immediately.

**Response:** `201 Created`

```json
{
  "call_id": "uuid-of-new-call",
  "scheduled_call_id": "uuid",
  "status": "pending",
  "type": "video",
  "ice_servers": [ ... ],
  "created_at": "ISO8601"
}
```

**Errors:** 403 (not creator or already started), 404, 422 (already started or cancelled).

---

## 4. Reminders (cron job)

### 4.1 When to run

- Every minute (or every 5 minutes): find `scheduled_calls` where:
  - `reminder_sent_at` is null,
  - `scheduled_at` is within the next N minutes (e.g. 5 minutes),
  - `started_call_id` is null (not yet started),
  - not cancelled.

### 4.2 What to do

1. Set `scheduled_calls.reminder_sent_at` = now (so we don’t send again).
2. For each invitee (and optionally the creator), send a push notification (or in-app event): “Call ‘Weekly sync’ in 5 minutes” with scheduled_call_id and scheduled_at.
3. Optional: store per-invitee reminder in `scheduled_call_invitees.reminder_sent_at` if you send different content per user.

### 4.3 Implementation

- Laravel: create a scheduled command (e.g. `php artisan schedule:run` with `SendScheduledCallReminders` command every minute). In the command, query as above and dispatch a job per scheduled call to send pushes (or use a notification class).

---

## 5. Notifications (optional)

- **On create:** Notify invitees “John scheduled a video call with you on Feb 17 at 14:00” (link to scheduled call or app).
- **On cancel:** Notify invitees “John cancelled the scheduled call.”
- **On reminder:** “Your call with John is in 5 minutes.”
- Use Laravel Notifications (database, mail, FCM, etc.) as per your stack.

---

## 6. Rate limiting

- Create scheduled call: 20 per user per day (avoid spam).
- Start scheduled call: same as create call (e.g. 30/min).

---

## 7. Summary checklist for backend

- [ ] Migrations: `scheduled_calls`, `scheduled_call_invitees`.
- [ ] `POST /api/scheduled-calls` (create).
- [ ] `GET /api/scheduled-calls` (list upcoming/past).
- [ ] `GET /api/scheduled-calls/{id}`.
- [ ] `PUT` or `PATCH /api/scheduled-calls/{id}` (optional).
- [ ] `DELETE /api/scheduled-calls/{id}` (cancel).
- [ ] `POST /api/scheduled-calls/{id}/start` (create real call, notify invitees, set started_call_id).
- [ ] Cron job: send reminders 5 min before scheduled_at; set reminder_sent_at.
- [ ] Optional: invite and cancel notifications.
