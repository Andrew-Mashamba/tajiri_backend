<?php

namespace App\Services\Earnings;

use Carbon\CarbonInterface;

class EarningEventDto
{
    // Identity
    public string $sourceType = 'post';     // 'post'|'comment'|'reply'|'live_stream'|'marketplace_order'
    public int $sourceId = 0;
    public ?int $postId = null;
    public ?int $commentId = null;

    // Actors
    public ?int $actorUserId = null;        // who fired the event (viewer / liker / commenter)
    public ?int $postAuthorId = null;       // resolved upstream by controller
    public ?int $commentAuthorId = null;
    public ?int $sharerUserId = null;       // populated if the actor came in via a share link
    public ?int $originalCreatorId = null;  // for derivative_royalty events

    // Metric
    public string $stream = 'engagement';   // 'engagement'|'fan_funding'|'marketplace'|'brand_deal'|'live_gifts'|'affiliate'
    public string $metric = 'view';         // 'view'|'reaction'|'comment'|'reply'|'share'|'save'|'watch_second'|...
    public int $rawCount = 1;

    // Multiplier inputs
    public ?float $watchCompletionPct = null;   // 0.0–1.0
    public ?int $videoDurationSeconds = null;
    public string $originalityFlag = 'original';
    public bool $discoveryModeActive = false;

    // Provenance + timing
    public ?string $fundingSource = null;
    public ?CarbonInterface $occurredAt = null;
}
