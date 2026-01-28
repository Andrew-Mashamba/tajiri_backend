# TAJIRI Livestreaming - Backend Specification

**Version**: 2.0
**Last Updated**: 2026-01-28

## Implementation Status

### Core Features (Completed)
- Database schema (8 base tables + 8 advanced tables)
- Stream CRUD endpoints (40+ routes)
- Status transitions (scheduled -> pre_live -> live -> ending -> ended)
- Broadcasting events (Pusher/Echo + Plain WebSocket)
- Scheduled jobs for status automation
- Notification system

### Advanced Features (Completed)
- Floating reactions with individual tracking
- Live polls with real-time voting
- Q&A mode with upvoting
- Super chat (tiered donations)
- Battle mode (PK battles)
- Stream health monitoring

## Database Tables

### Base Tables
1. `live_streams` - Main stream data with health monitoring columns
2. `stream_viewers` - Viewer tracking
3. `stream_comments` - Live chat
4. `stream_likes` - Like tracking
5. `stream_gifts` - Gift transactions
6. `stream_cohosts` - Co-host management
7. `stream_notifications` - Notification tracking
8. `stream_analytics` - Time-series analytics

### Advanced Tables
1. `stream_reactions` - Individual reaction tracking
2. `stream_polls` - Live polls
3. `stream_poll_options` - Poll options
4. `stream_poll_votes` - Poll votes (one per user)
5. `stream_questions` - Q&A questions
6. `stream_question_upvotes` - Question upvotes
7. `stream_super_chats` - Super chat messages with tiers
8. `stream_battles` - PK battle state

## API Endpoints

### Stream Management
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | /api/streams | List streams |
| POST | /api/streams | Create stream |
| GET | /api/streams/{id} | Get stream |
| PATCH | /api/streams/{id}/status | Change status |
| POST | /api/streams/{id}/start | Go live |
| POST | /api/streams/{id}/end | End stream |

### Engagement
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | /api/streams/{id}/join | Join as viewer |
| POST | /api/streams/{id}/leave | Leave stream |
| POST | /api/streams/{id}/like | Toggle like |
| POST | /api/streams/{id}/reaction | Simple reaction |
| POST | /api/streams/{id}/reactions | Tracked reaction |
| GET | /api/streams/{id}/comments | Get comments |
| POST | /api/streams/{id}/comments | Post comment |
| POST | /api/streams/{id}/gifts | Send gift |

### Polls
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | /api/streams/{id}/polls/active | Get active poll |
| POST | /api/streams/{id}/polls | Create poll (broadcaster) |
| POST | /api/streams/{id}/polls/{pollId}/vote | Vote on poll |
| POST | /api/streams/{id}/polls/{pollId}/close | Close poll (broadcaster) |

### Q&A
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | /api/streams/{id}/questions | Get questions (sorted by upvotes) |
| POST | /api/streams/{id}/questions | Submit question |
| POST | /api/streams/{id}/questions/{id}/upvote | Toggle upvote |
| POST | /api/streams/{id}/questions/{id}/answer | Mark answered (broadcaster) |

### Super Chat
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | /api/streams/{id}/super-chats | Send super chat |

**Tier Thresholds (TZS):**
- Low: 1,000 - 2,999 (5s display, blue)
- Medium: 3,000 - 9,999 (10s display, amber)
- High: 10,000+ (15s display, red)

### Battles
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | /api/streams/{id}/battles/invite | Invite to battle |
| GET | /api/battles/{battleId} | Get battle status |
| POST | /api/battles/{battleId}/accept | Accept battle |
| POST | /api/battles/{battleId}/decline | Decline battle |
| POST | /api/battles/{battleId}/end | End battle |

### Health Monitoring
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | /api/streams/{id}/health | Report health metrics |

## WebSocket Events

### Plain WebSocket (Recommended)
Connection: `wss://zima-uat.site/streams/{stream_id}?user_id={user_id}`

| Event | Description |
|-------|-------------|
| `viewer_count_updated` | Viewer count changed |
| `new_comment` | New comment posted |
| `gift_sent` | Gift sent |
| `reaction` | Reaction sent |
| `status_changed` | Stream status changed |
| `poll_created` | New poll created |
| `poll_vote` | Poll vote received |
| `poll_closed` | Poll closed |
| `question_submitted` | New Q&A question |
| `question_upvoted` | Question upvote changed |
| `question_answered` | Question marked answered |
| `super_chat_sent` | Super chat sent |
| `battle_invite` | Battle invitation |
| `battle_accepted` | Battle started |
| `battle_score_update` | Battle score changed |
| `battle_ended` | Battle ended |
| `ping`/`pong` | Heartbeat |

## Files Created/Modified

### Models
- `app/Models/LiveStream.php` (updated with health fields + relationships)
- `app/Models/StreamReaction.php`
- `app/Models/StreamPoll.php`
- `app/Models/StreamPollOption.php`
- `app/Models/StreamPollVote.php`
- `app/Models/StreamQuestion.php`
- `app/Models/StreamQuestionUpvote.php`
- `app/Models/StreamSuperChat.php`
- `app/Models/StreamBattle.php`

### Controllers
- `app/Http/Controllers/Api/LiveStreamController.php`
- `app/Http/Controllers/Api/AdvancedStreamController.php`

### Services
- `app/Services/WebSocket/StreamWebSocketHandler.php`
- `app/Services/WebSocket/WebSocketBroadcaster.php`

### Events
- `app/Events/StreamStatusChanged.php`
- `app/Events/ViewerCountUpdated.php`
- `app/Events/NewStreamComment.php`
- `app/Events/GiftReceived.php`
- `app/Events/CoHostJoined.php`
- `app/Events/StreamEnded.php`

### Jobs
- `app/Jobs/TransitionToPreLive.php`
- `app/Jobs/TransitionToEnded.php`
- `app/Jobs/UpdateViewerCount.php`
- `app/Jobs/GenerateStreamAnalytics.php`

### Migrations
- `2026_01_09_210103_create_livestream_tables.php` (base tables)
- `2026_01_28_100000_upgrade_livestream_tables.php` (status upgrade)
- `2026_01_28_151050_update_live_streams_status_constraint.php` (PostgreSQL fix)
- `2026_01_28_160000_create_advanced_streaming_tables.php` (advanced features)

## External Requirements

1. **WebSocket Server**: BeyondCode Laravel WebSockets or custom Ratchet/Swoole
2. **Redis**: For real-time viewer counting and pub/sub
3. **RTMP Server**: nginx-rtmp-module for stream ingest
4. **Push Notifications**: FCM for mobile notifications

## Route Summary

Total routes: 40+
- Stream management: 6
- Viewers/engagement: 8
- Comments: 3
- Gifts: 3
- Co-hosts: 3
- Notifications: 2
- Analytics: 1
- Polls: 4
- Q&A: 4
- Super chat: 1
- Battles: 5
- Health: 1
