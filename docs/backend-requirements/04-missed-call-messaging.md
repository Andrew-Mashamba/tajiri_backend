# Backend Requirements: Missed-Call Messaging

**Feature:** VC-7 (Missed-call messaging — caller leaves a short voice message after a missed call)  
**Phase:** 4  
**Depends on:** [01-call-signaling-and-turn.md](01-call-signaling-and-turn.md) (call lifecycle, call_id), and existing **chat/messaging** API in your app.

---

## 1. Overview

- When a call ends with status `no_answer` (or optionally `rejected`), the **caller** can leave a short voice message that is delivered as a message in the existing 1:1 chat (or conversation) with the callee.
- Backend must: (1) allow uploading a voice file and associating it with the missed call, and (2) create a message in the conversation with type “missed_call_voice” (or equivalent) so the callee sees it in chat.

---

## 2. Assumptions

- Your app already has:
  - **Conversations / chats** between two users (e.g. `conversations` table or thread id).
  - **Messages** (e.g. `messages` table with conversation_id, sender_id, type, body/attachment, etc.).
  - An API to send messages (e.g. `POST /api/conversations/{id}/messages` or `POST /api/chats/{id}/messages`).
- This document adds: a dedicated endpoint for “missed-call voice message” that creates the conversation (if needed), uploads the voice file, and creates a message linked to the call.

---

## 3. Database (if not already present)

### 3.1 Messages table (existing or new)

Ensure messages support:

- `type` or `message_type`: include value `missed_call_voice`.
- `call_session_id` or `call_id`: nullable, link to `call_sessions.id` for “this message is the voice follow-up for this missed call”.
- Attachment: voice file stored (e.g. in `attachments` table or `messages.attachment_url`). Store duration (seconds) if available.

### 3.2 Optional: dedicated column on call_sessions

- `missed_call_voice_message_id`: nullable, FK to messages.id. Set when caller posts the voice message; useful for “already left a message” check.

---

## 4. API specification

### 4.1 Endpoint

**Endpoint:** `POST /api/calls/{id}/missed-call-voice-message`

**Purpose:** Caller uploads a voice recording after a missed call; backend creates (or finds) the 1:1 conversation with the callee and posts a message of type `missed_call_voice` with the voice attachment.

**URL parameters:** `id` = call_session id (or UUID).

**Request:** `multipart/form-data` (or `application/json` with base64 — prefer multipart for large files).

| Field | Type | Required | Rules | Description |
|-------|------|----------|--------|-------------|
| `voice` | file | Yes | file; mime:audio/* (e.g. audio/mpeg, audio/ogg, audio/webm); max size e.g. 5 MB; max duration 2 min | Voice recording file. |
| `duration_seconds` | number | No | numeric; min 0; max 120 | Duration of the recording (for display). |

**Validation:**

- Call must exist and status must be `no_answer` (or your product may allow also after `rejected`).
- Authenticated user must be the **caller** (call_sessions.caller_id).
- Optional: allow only one missed-call voice message per call (check that no message with this call_id and type missed_call_voice exists). If already sent, return 422 with message "You have already left a voice message for this call."
- Validate file type and size (e.g. max 5 MB, max 2 minutes).

**Business logic:**

1. Resolve 1:1 conversation between caller and callee (create if not exists). How you resolve: e.g. `conversations` where (user_id_1, user_id_2) = (caller, callee) in canonical order.
2. Store the uploaded file in your storage (e.g. Laravel Storage, S3). Generate a unique path (e.g. `calls/missed-voice/{call_id}/{uuid}.webm`).
3. Create a message in that conversation:
   - sender_id = caller
   - type = `missed_call_voice`
   - call_session_id = id (or call_id)
   - attachment: url to the stored file, content_type (audio), duration_seconds if provided
   - Optional: body = "" or a system text like "Voice message after missed call"
4. Optionally set `call_sessions.missed_call_voice_message_id` = new message id.
5. Notify callee (push / WebSocket) that a new message arrived in the conversation, so the chat UI updates and optionally shows a notification.

**Response:** `201 Created`

```json
{
  "message_id": 9001,
  "conversation_id": 100,
  "call_id": "uuid",
  "type": "missed_call_voice",
  "attachment_url": "https://storage.example.com/...",
  "duration_seconds": 15,
  "created_at": "2025-02-16T10:10:00.000000Z"
}
```

**Errors:**

- 400 — Invalid file or validation failed.
- 403 — User is not the caller.
- 404 — Call not found.
- 422 — Call not missed (e.g. was accepted) or voice message already left for this call.
- 413 — File too large.
- 429 — Rate limit (e.g. 5 per hour per user).

---

## 5. Get message in chat

- Your existing “list messages” API should return this message like any other, with:
  - `type`: `missed_call_voice`
  - `call_id` or `call_session_id`: so client can show “After missed call” or link to call log.
  - `attachment_url` (or equivalent) for the voice file.
  - `duration_seconds` for UI (progress bar, duration label).

No new endpoint required if your messages list already supports these fields.

---

## 6. Security and abuse

- Only the caller can post a missed-call voice message for that call.
- Rate limit: e.g. 5 missed-call voice messages per user per hour (or per call: 1).
- Do not allow this for calls that were connected (status = connected/ended after connect); only no_answer (and optionally rejected).

---

## 7. Summary checklist for backend

- [ ] Ensure messages (or attachments) support type `missed_call_voice` and optional `call_session_id`.
- [ ] `POST /api/calls/{id}/missed-call-voice-message` (multipart: voice file, optional duration_seconds).
- [ ] Validate: call exists, status no_answer, user is caller, optional idempotency (one message per call).
- [ ] Store file; create conversation if needed; create message with attachment and call_session_id.
- [ ] Notify callee (push/WebSocket) for new message.
- [ ] Rate limit and return 201 with message_id, conversation_id, attachment_url, duration_seconds.
