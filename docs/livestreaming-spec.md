# TAJIRI Livestreaming - Laravel Backend Requirements

## Military-Grade Livestreaming System - Backend Specification

> **Last Updated:** 2026-01-28
> **Latest Changes:**
> - Added detailed Plain WebSocket implementation (Section 3, Option 1) with event formats and Laravel code examples
> - Documented WebSocket connection handling, broadcasting, and viewer count tracking
> - Added backend pseudocode for real-time comment/gift broadcasting
> - Kept Pusher/Laravel Echo option as alternative (Section 3, Option 2)

## Implementation Status

### Completed
- Database schema (8 tables + upgrade migration for new statuses/columns)
- Stream CRUD endpoints (23+ routes)
- Status transition logic with validation (scheduled -> pre_live -> live -> ending -> ended)
- Broadcasting events (Pusher/Echo): StreamStatusChanged, ViewerCountUpdated, NewStreamComment, GiftReceived, CoHostJoined, StreamEnded
- Plain WebSocket: WebSocketBroadcaster service, StreamWebSocketHandler
- Scheduled jobs: TransitionToPreLive, TransitionToEnded, UpdateViewerCount, GenerateStreamAnalytics
- Notification system (stream_notifications table + endpoints)
- Analytics endpoint with retention data
- Reaction endpoint and broadcasting
- Comment rate limiting via WebSocket

### Requires External Setup
- RTMP server (Nginx-RTMP / AWS IVS)
- WebSocket server (BeyondCode Laravel WebSockets / Ratchet / Swoole)
- Redis for real-time viewer count tracking
- Push notification service (FCM)

## WebSocket Options

### Option 1: Plain WebSocket (Recommended)
- Connection: wss://zima-uat.site/streams/{stream_id}?user_id={user_id}
- Format: {"event": "event_name", "data": {...}}
- Events: viewer_count_updated, new_comment, gift_sent, reaction, status_changed, ping/pong
- Implementation: app/Services/WebSocket/StreamWebSocketHandler.php + WebSocketBroadcaster.php

### Option 2: Pusher/Laravel Echo (Alternative)
- Channel: stream.{stream_id}
- Events: StreamStatusChanged, ViewerCountUpdated, NewStreamComment, GiftReceived, CoHostJoined
- Implementation: app/Events/*.php (6 event classes)
