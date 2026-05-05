# Creators Fund — Engagement Endpoint Hook Wiring

> Implementation guide for Tasks 26–40 of `docs/superpowers/plans/2026-05-03-creators-fund-engine.md`. Each section below documents one engagement endpoint that must fire `EarningsEngine::recordEvent($dto)`.

The hook pattern is identical for every endpoint:

1. Add `use FiresEarningEvents;` (and the `use App\Http\Controllers\Api\Concerns\FiresEarningEvents;` import) to the controller class.
2. Add `use App\Services\Earnings\EarningEventDto;` import.
3. After the existing engagement persistence (after the like/save/comment row is created), call `$this->fireEarning(fn () => $dto)` to fire the earning event. This wraps in try/catch so an earnings failure can never break the engagement endpoint.

The deliberately fire-and-forget design means: even if the Creators Fund engine has a bug, posts/likes/comments still work normally.

---

## Task 26 — `POST /posts/{id}/view` (PostController::recordView)

After the view-counter increment, append:

```php
$post = \App\Models\Post::select(['id','user_id','duration_seconds','is_discovery_mode','reply_to_post_id','stitch_from_post_id','quote_from_post_id'])->find($id);
if ($post) {
    $sharerUserId = null;
    if ($via = $request->input('via')) {
        $attribution = \DB::table('post_share_attributions')
            ->where('share_uid', $via)
            ->where('expires_at', '>', now())
            ->first();
        if ($attribution) {
            $sharerUserId = (int) $attribution->sharer_user_id;
        }
    } elseif ($request->input('sharer_user_id')) {
        $sharerUserId = (int) $request->input('sharer_user_id');
    }
    $watchPct = $request->filled('watch_completion_pct') ? (float) $request->input('watch_completion_pct') : null;

    $this->fireEarning(function () use ($post, $request, $sharerUserId, $watchPct) {
        $dto = new EarningEventDto();
        $dto->sourceType = 'post';
        $dto->sourceId = (int) $post->id;
        $dto->postId = (int) $post->id;
        $dto->postAuthorId = (int) $post->user_id;
        $dto->actorUserId = $request->input('user_id') ? (int) $request->input('user_id') : null;
        $dto->sharerUserId = $sharerUserId;
        $dto->stream = 'engagement';
        $dto->metric = 'view';
        $dto->watchCompletionPct = $watchPct;
        $dto->videoDurationSeconds = $post->duration_seconds ? (int) $post->duration_seconds : null;
        $dto->originalityFlag = \App\Services\OriginalityDetector::classify((int) $post->id);
        $dto->discoveryModeActive = (bool) ($post->is_discovery_mode ?? false);
        return $dto;
    });
}
```

---

## Task 27 — `POST /posts/{id}/like` (PostController::like)

After persisting the like:

```php
$post = \App\Models\Post::select(['id','user_id'])->find($id);
if ($post) {
    $reactionType = $request->input('reaction_type', 'like');
    $this->fireEarning(function () use ($post, $request, $reactionType) {
        $dto = new EarningEventDto();
        $dto->sourceType = 'post';
        $dto->sourceId = (int) $post->id;
        $dto->postId = (int) $post->id;
        $dto->postAuthorId = (int) $post->user_id;
        $dto->actorUserId = (int) $request->input('user_id');
        $dto->stream = 'engagement';
        $dto->metric = 'reaction';
        $dto->fundingSource = "reaction_type:{$reactionType}";
        return $dto;
    });
}
```

`unlike()` does NOT reverse the credit (per design, strategy §8 silent on retroactive un-credit).

---

## Task 28 — `POST /posts/{id}/save` (PostController::savePost)

```php
$post = \App\Models\Post::select(['id','user_id'])->find($id);
if ($post) {
    $this->fireEarning(function () use ($post, $request) {
        $dto = new EarningEventDto();
        $dto->sourceType = 'post';
        $dto->sourceId = (int) $post->id;
        $dto->postId = (int) $post->id;
        $dto->postAuthorId = (int) $post->user_id;
        $dto->actorUserId = (int) $request->input('user_id');
        $dto->stream = 'engagement';
        $dto->metric = 'save';
        return $dto;
    });
}
```

---

## Task 29 — `POST /posts/{id}/share` + sharer attribution chain (PostController::share)

```php
$post = \App\Models\Post::select(['id','user_id'])->find($id);
if (!$post) return response()->json(['success'=>false,'message'=>'Post not found'], 404);

$shareUid = (string) \Illuminate\Support\Str::uuid();
\DB::table('post_share_attributions')->insert([
    'share_uid'      => $shareUid,
    'post_id'        => (int) $post->id,
    'sharer_user_id' => (int) $request->input('user_id'),
    'expires_at'     => now()->addDays(30),
    'created_at'     => now(),
    'updated_at'     => now(),
]);

$this->fireEarning(function () use ($post, $request) {
    $dto = new EarningEventDto();
    $dto->sourceType = 'post';
    $dto->sourceId = (int) $post->id;
    $dto->postId = (int) $post->id;
    $dto->postAuthorId = (int) $post->user_id;
    $dto->actorUserId = (int) $request->input('user_id');
    $dto->stream = 'engagement';
    $dto->metric = 'share';
    return $dto;
});

return response()->json(['success'=>true,'data'=>['share_uid'=>$shareUid]]);
```

The `share_uid` returned here gets embedded in share URLs (`?via=<uid>`) so future view events can attribute back to the sharer (see Task 26).

---

## Task 30 — `POST /posts/{id}/comments` (CommentController::store)

After creating the comment row:

```php
$post = \App\Models\Post::select(['id','user_id'])->find($id);
if ($post) {
    $parentCommentId = $request->filled('parent_id') ? (int) $request->input('parent_id') : null;
    $parentCommentAuthorId = null;
    if ($parentCommentId) {
        $parentCommentAuthorId = (int) \DB::table('comments')->where('id', $parentCommentId)->value('user_id');
    }
    $this->fireEarning(function () use ($post, $request, $comment, $parentCommentId, $parentCommentAuthorId) {
        $dto = new EarningEventDto();
        $dto->sourceType = $parentCommentId ? 'reply' : 'comment';
        $dto->sourceId = (int) $comment->id;
        $dto->postId = (int) $post->id;
        $dto->commentId = (int) $comment->id;
        $dto->postAuthorId = (int) $post->user_id;
        $dto->commentAuthorId = $parentCommentAuthorId;
        $dto->actorUserId = (int) $request->input('user_id');
        $dto->stream = 'engagement';
        $dto->metric = $parentCommentId ? 'reply' : 'comment';
        return $dto;
    });
}
```

---

## Task 31 — `POST /comments/{id}/like` (CommentController::like)

```php
$comment = \DB::table('comments')->where('id', $id)->first();
if ($comment) {
    $post = \App\Models\Post::select(['id','user_id'])->find($comment->post_id);
    $this->fireEarning(function () use ($comment, $post, $request) {
        $dto = new EarningEventDto();
        $dto->sourceType = 'comment';
        $dto->sourceId = (int) $comment->id;
        $dto->postId = $post ? (int) $post->id : null;
        $dto->commentId = (int) $comment->id;
        $dto->postAuthorId = $post ? (int) $post->user_id : null;
        $dto->commentAuthorId = (int) $comment->user_id;
        $dto->actorUserId = (int) $request->input('user_id');
        $dto->stream = 'engagement';
        $dto->metric = 'comment_reaction';
        return $dto;
    });
}
```

---

## Tasks 32-34 — Derivative content (PostController::store)

After the new post is created, check for `reply_to_post_id`, `stitch_from_post_id`, and `quote_from_post_id`. For each present, fire one `metric=derivative_royalty` event:

```php
foreach ([
    'reply_to_post_id'   => 'reply',
    'stitch_from_post_id'=> 'stitch',
    'quote_from_post_id' => 'quote',
] as $field => $kind) {
    if ($request->filled($field)) {
        $original = \App\Models\Post::select(['id','user_id'])->find((int) $request->input($field));
        if ($original) {
            $this->fireEarning(function () use ($post, $original, $kind) {
                $dto = new EarningEventDto();
                $dto->sourceType = 'post';
                $dto->sourceId = (int) $post->id;
                $dto->postId = (int) $post->id;
                $dto->postAuthorId = (int) $post->user_id;
                $dto->originalCreatorId = (int) $original->user_id;
                $dto->actorUserId = (int) $post->user_id;
                $dto->stream = 'engagement';
                $dto->metric = 'derivative_royalty';
                $dto->originalityFlag = 'derivative_substantial';
                $dto->fundingSource = "derivative:{$kind}";
                return $dto;
            });
        }
    }
}
```

---

## Task 35 — `POST /follows` discovery credit (FollowController::follow)

If `origin_post_id` set:

```php
if ($request->filled('origin_post_id')) {
    $post = \App\Models\Post::select(['id','user_id'])->find((int) $request->input('origin_post_id'));
    if ($post && $post->user_id !== (int) $request->input('follower_id')) {
        $this->fireEarning(function () use ($post, $request) {
            $dto = new EarningEventDto();
            $dto->sourceType = 'post';
            $dto->sourceId = (int) $post->id;
            $dto->postId = (int) $post->id;
            $dto->postAuthorId = (int) $post->user_id;
            $dto->actorUserId = (int) $request->input('follower_id');
            $dto->stream = 'engagement';
            $dto->metric = 'follow_from_post';
            return $dto;
        });
    }
}
```

The follow row should also persist `origin_post_id` (column added in migration `2026_05_03_000009`).

---

## Task 36 — Subscribe discovery credit (SubscriptionController)

Same pattern as Task 35 with `metric=subscribe_from_post`. Plus a separate fan_funding pass-through credit at the same point:

```php
$this->fireEarning(function () use ($post, $subscription, $request) {
    $dto = new EarningEventDto();
    $dto->sourceType = 'subscription';
    $dto->sourceId = (int) $subscription->id;
    $dto->postId = $post ? (int) $post->id : null;
    $dto->postAuthorId = (int) $subscription->creator_id;
    $dto->actorUserId = (int) $subscription->subscriber_id;
    $dto->stream = 'fan_funding';
    $dto->metric = 'subscription';
    $dto->rawCount = max(1, (int) round((float) $subscription->amount_tsh));
    $dto->fundingSource = "fan:{$subscription->subscriber_id}";
    return $dto;
});
```

---

## Task 37 — `POST /streams/{id}/gifts` (LiveStreamController::sendGift)

```php
$stream = \DB::table('live_streams')->where('id', $id)->first();
if ($stream) {
    $giftValueTsh = (float) $request->input('value_tsh', 0);
    $this->fireEarning(function () use ($stream, $request, $giftValueTsh) {
        $dto = new EarningEventDto();
        $dto->sourceType = 'live_stream';
        $dto->sourceId = (int) $stream->id;
        $dto->postAuthorId = (int) $stream->host_user_id;
        $dto->actorUserId = (int) $request->input('sender_user_id');
        $dto->stream = 'live_gifts';
        $dto->metric = 'live_gift';
        $dto->rawCount = max(1, (int) round($giftValueTsh));
        $dto->fundingSource = "fan:{$request->input('sender_user_id')}";
        return $dto;
    });
}
```

---

## Task 38 — `POST /streams/{id}/super-chats` (LiveStreamController::sendSuperChat)

If endpoint exists, identical shape with `metric='super_chat'`. If endpoint absent, defer to v2.

---

## Task 39 — `POST /streams/{id}/reactions` (AdvancedStreamController::storeReaction)

```php
$liveStream = \DB::table('live_streams')->where('id', $id)->first();
if ($liveStream) {
    $this->fireEarning(function () use ($liveStream, $request) {
        $dto = new EarningEventDto();
        $dto->sourceType = 'live_stream';
        $dto->sourceId = (int) $liveStream->id;
        $dto->postAuthorId = (int) $liveStream->host_user_id;
        $dto->actorUserId = (int) $request->input('user_id');
        $dto->stream = 'engagement';
        $dto->metric = 'live_reaction';
        return $dto;
    });
}
```

---

## Task 40 — `POST /shop/orders` (ShopOrderController::store)

After the order is persisted, if linked to a post:

```php
$postId = (int) $request->input('post_id', 0);
if ($postId > 0) {
    $post = \App\Models\Post::select(['id','user_id'])->find($postId);
    if ($post) {
        $this->fireEarning(function () use ($post, $order, $request) {
            $dto = new EarningEventDto();
            $dto->sourceType = 'marketplace_order';
            $dto->sourceId = (int) $order->id;
            $dto->postId = (int) $post->id;
            $dto->postAuthorId = (int) $post->user_id;
            $dto->actorUserId = (int) $request->input('buyer_id');
            $dto->stream = 'marketplace';
            $dto->metric = 'marketplace_sale';
            $dto->rawCount = max(1, (int) round((float) $order->total_amount));
            $dto->fundingSource = "buyer:{$request->input('buyer_id')}";
            return $dto;
        });
    }
}
```

---

## Verification

After each hook is wired, fire the corresponding endpoint with curl and confirm a row appears in `earning_events`:

```sql
SELECT id, post_id, target_user_id, actor_role, stream, metric, gross_credit, is_chargeable, charge_reason
FROM earning_events
ORDER BY id DESC
LIMIT 10;
```

A non-chargeable event with a `charge_reason` is normal — that's how the AbuseGuard records anti-abuse rejections (audit trail preserved).
