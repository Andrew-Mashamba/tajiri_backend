# Backend Requirements — Video & Audio Calls

**Audience:** Backend developers (Laravel on Ubuntu)  
**Purpose:** Detailed, implementable specifications for each feature area. Use with [../implementation-plan.md](../implementation-plan.md).

---

## Conventions

- **Auth:** All endpoints require authentication (e.g. `auth:sanctum`). Request user = `request()->user()` or `Auth::user()`.
- **Errors:** Return JSON with `message` and optional `errors` (validation). Use HTTP status: `400` validation, `403` forbidden, `404` not found, `422` business rule, `429` rate limit.
- **Ids:** Use UUIDs for `call_id` and public ids in APIs; internal DB can use auto-increment if preferred.
- **Time:** Store and return UTC; client converts to local.

---

## Documents

| # | Document | Features | Phase |
|---|----------|----------|-------|
| 1 | [01-call-signaling-and-turn.md](01-call-signaling-and-turn.md) | CC-1, CC-2, CC-5, VC-1, VD-1, TURN, call log | 0, 1 |
| 2 | [02-group-calls-and-participants.md](02-group-calls-and-participants.md) | VC-5, VD-6, GC-1, GC-2, GC-4, GC-6 | 2 |
| 3 | [03-call-reactions-and-raise-hand.md](03-call-reactions-and-raise-hand.md) | VC-6, VD-9, GC-5 | 4 |
| 4 | [04-missed-call-messaging.md](04-missed-call-messaging.md) | VC-7 | 4 |
| 5 | [05-scheduled-calls.md](05-scheduled-calls.md) | VC-8 | 4 |
| 6 | [06-push-notifications-and-reconnect.md](06-push-notifications-and-reconnect.md) | Push, CC-3, rate limits | 5 |

Implement in order 1 → 2 → 3 → 4 → 5 → 6 (or as per implementation plan phases).
