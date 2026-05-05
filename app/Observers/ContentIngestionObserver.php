<?php

namespace App\Observers;

use App\Jobs\ContentEngine\ContentIngestionJob;
use App\Jobs\ContentEngine\DeleteContentDocumentJob;
use App\Services\ContentEngine\ContentDocumentFactory;
use Illuminate\Database\Eloquent\Model;

class ContentIngestionObserver
{
    /**
     * Map of model classes to source types for deletion.
     */
    private static array $sourceTypeMap = [
        \App\Models\Post::class => 'post',
        \App\Models\Clip::class => 'clip',
        \App\Models\Story::class => 'story',
        \App\Models\MusicTrack::class => 'music',
        \App\Models\LiveStream::class => 'stream',
        \App\Models\Event::class => 'event',
        \App\Models\Campaign::class => 'campaign',
        \App\Models\Shop\Product::class => 'product',
        \App\Models\Group::class => 'group',
        \App\Models\Page::class => 'page',
        \App\Models\UserProfile::class => 'user_profile',
        \App\Models\GossipThread::class => 'gossip_thread',
    ];

    public function created(Model $model): void
    {
        $this->dispatch($model);
    }

    public function updated(Model $model): void
    {
        $this->dispatch($model);
    }

    public function deleted(Model $model): void
    {
        $sourceType = self::$sourceTypeMap[get_class($model)] ?? null;

        if ($sourceType) {
            DeleteContentDocumentJob::dispatch($sourceType, $model->id);
        }
    }

    private function dispatch(Model $model): void
    {
        // Skip drafts and unpublished posts
        if ($model instanceof \App\Models\Post) {
            if ($model->is_draft || $model->status === 'draft') {
                return;
            }
        }

        // Skip non-ended streams (only index archives)
        if ($model instanceof \App\Models\LiveStream) {
            if (!in_array($model->status, ['ended'], true)) {
                return;
            }
        }

        try {
            $sourceType = ContentDocumentFactory::sourceType($model);
            ContentIngestionJob::dispatch($sourceType, $model->id, get_class($model));
        } catch (\InvalidArgumentException $e) {
            // Unsupported model type — skip
        }
    }
}
