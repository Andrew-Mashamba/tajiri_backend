<?php

namespace App\Observers;

use App\Services\ContentEngine\GraphEdgeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class ContentGraphObserver
{
    public function created(Model $model): void
    {
        $this->buildEdges($model);
    }

    public function updated(Model $model): void
    {
        // Only rebuild edges if relationship columns changed
        if ($model instanceof \App\Models\Post) {
            $changed = $model->isDirty([
                'original_post_id', 'reply_to_post_id', 'stitch_from_post_id',
                'tagged_users', 'content_tags',
            ]);
            if (!$changed) return;
        }

        $this->buildEdges($model);
    }

    private function buildEdges(Model $model): void
    {
        try {
            if ($model instanceof \App\Models\Post) {
                // Skip drafts
                if ($model->is_draft || $model->status === 'draft') return;
                GraphEdgeService::buildEdgesForPost($model);
            } elseif ($model instanceof \App\Models\Clip) {
                GraphEdgeService::buildEdgesForClip($model);
            } elseif ($model instanceof \App\Models\GossipThread) {
                GraphEdgeService::buildEdgesForGossipThread($model);
            } else {
                // For other content types, just build CREATOR_OF edge
                $sourceType = match (true) {
                    $model instanceof \App\Models\Story => 'story',
                    $model instanceof \App\Models\MusicTrack => 'music',
                    $model instanceof \App\Models\LiveStream => 'stream',
                    $model instanceof \App\Models\Event => 'event',
                    $model instanceof \App\Models\Campaign => 'campaign',
                    $model instanceof \App\Models\Shop\Product => 'product',
                    default => null,
                };
                if ($sourceType && isset($model->user_id)) {
                    GraphEdgeService::buildCreatorEdge($sourceType, $model->id, $model->user_id);
                }
            }
        } catch (\Throwable $e) {
            // Graph edge creation is non-critical
            Log::warning("ContentGraphObserver: edge creation failed", [
                'model' => get_class($model),
                'id' => $model->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
