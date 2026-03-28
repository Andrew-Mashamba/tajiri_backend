<?php

namespace App\Console\Commands;

use App\Jobs\ContentEngine\ContentIngestionJob;
use App\Models\Campaign;
use App\Models\Clip;
use App\Models\ContentDocument;
use App\Models\Event;
use App\Models\GossipThread;
use App\Models\Group;
use App\Models\LiveStream;
use App\Models\MusicTrack;
use App\Models\Page;
use App\Models\Post;
use App\Models\Shop\Product;
use App\Models\Story;
use App\Models\UserProfile;
use Illuminate\Console\Command;

class ContentReindex extends Command
{
    protected $signature = 'content:reindex
                            {--all : Reindex all content types}
                            {--type= : Specific type to reindex (posts, clips, stories, music, streams, events, campaigns, products, groups, pages, profiles, gossip)}
                            {--since= : Only reindex content created/updated after this date (Y-m-d)}
                            {--batch-size=100 : Number of records per batch}';

    protected $description = 'Backfill content_documents by dispatching ingestion jobs for existing content';

    private const TYPE_MAP = [
        'posts'     => [Post::class,        'post'],
        'clips'     => [Clip::class,        'clip'],
        'stories'   => [Story::class,       'story'],
        'music'     => [MusicTrack::class,  'music'],
        'streams'   => [LiveStream::class,  'stream'],
        'events'    => [Event::class,       'event'],
        'campaigns' => [Campaign::class,    'campaign'],
        'products'  => [Product::class,     'product'],
        'groups'    => [Group::class,       'group'],
        'pages'     => [Page::class,        'page'],
        'profiles'  => [UserProfile::class, 'user_profile'],
        'gossip'    => [GossipThread::class,'gossip_thread'],
    ];

    public function handle(): int
    {
        $types = $this->option('all')
            ? array_keys(self::TYPE_MAP)
            : ($this->option('type') ? [$this->option('type')] : []);

        if (empty($types)) {
            $this->error('Specify --all or --type=<type>. Available types: ' . implode(', ', array_keys(self::TYPE_MAP)));
            return 1;
        }

        $since       = $this->option('since');
        $batchSize   = (int) $this->option('batch-size');
        $totalDispatched = 0;

        foreach ($types as $typeName) {
            if (!isset(self::TYPE_MAP[$typeName])) {
                $this->error("Unknown type: {$typeName}");
                continue;
            }

            [$modelClass, $sourceType] = self::TYPE_MAP[$typeName];

            $this->info("Reindexing {$typeName}...");

            $query = $modelClass::query();

            // Filter by date if specified
            if ($since) {
                $query->where('created_at', '>=', $since);
            }

            // Type-specific filters
            if ($modelClass === Post::class) {
                $query->where(function ($q) {
                    $q->where('is_draft', false)->orWhereNull('is_draft');
                })->where(function ($q) {
                    $q->where('status', 'published')->orWhereNull('status');
                });
            }
            if ($modelClass === LiveStream::class) {
                $query->where('status', 'ended');
            }

            $total = $query->count();
            $this->info("  Found {$total} records");

            $bar = $this->output->createProgressBar($total);
            $bar->start();

            $query->orderBy('id')
                ->chunk($batchSize, function ($records) use ($sourceType, $modelClass, &$totalDispatched, $bar) {
                    foreach ($records as $record) {
                        ContentIngestionJob::dispatch($sourceType, $record->id, $modelClass);
                        $totalDispatched++;
                        $bar->advance();
                    }
                });

            $bar->finish();
            $this->newLine();
        }

        $this->info("Dispatched {$totalDispatched} ingestion jobs total.");
        return 0;
    }
}
