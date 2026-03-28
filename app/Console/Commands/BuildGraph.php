<?php

namespace App\Console\Commands;

use App\Models\Clip;
use App\Models\ContentDocument;
use App\Models\GossipThread;
use App\Models\Post;
use App\Services\ContentEngine\GraphEdgeService;
use Illuminate\Console\Command;

class BuildGraph extends Command
{
    protected $signature = 'content:build-graph
                            {--type= : Only build for a specific type (posts, clips, gossip, creators)}
                            {--batch-size=100 : Records per batch}';
    protected $description = 'Backfill content_graph_edges from existing content relationships';

    public function handle(): int
    {
        $type = $this->option('type');
        $batchSize = (int) $this->option('batch-size');
        $totalEdges = 0;

        $types = $type ? [$type] : ['posts', 'clips', 'gossip', 'creators'];

        foreach ($types as $typeName) {
            $this->info("Building edges for {$typeName}...");
            $edges = match ($typeName) {
                'posts' => $this->buildPostEdges($batchSize),
                'clips' => $this->buildClipEdges($batchSize),
                'gossip' => $this->buildGossipEdges($batchSize),
                'creators' => $this->buildCreatorEdges($batchSize),
                default => 0,
            };
            $this->info("  {$edges} edges created");
            $totalEdges += $edges;
        }

        $this->info("Total edges created: {$totalEdges}");
        $edgeCount = \App\Models\ContentGraphEdge::count();
        $this->info("Total edges in graph: {$edgeCount}");

        return 0;
    }

    private function buildPostEdges(int $batchSize): int
    {
        $edges = 0;
        Post::where(function ($q) {
            $q->where('is_draft', false)->orWhereNull('is_draft');
        })->orderBy('id')->chunk($batchSize, function ($posts) use (&$edges) {
            foreach ($posts as $post) {
                $edges += GraphEdgeService::buildEdgesForPost($post);
            }
        });
        return $edges;
    }

    private function buildClipEdges(int $batchSize): int
    {
        $edges = 0;
        Clip::orderBy('id')->chunk($batchSize, function ($clips) use (&$edges) {
            foreach ($clips as $clip) {
                $edges += GraphEdgeService::buildEdgesForClip($clip);
            }
        });
        return $edges;
    }

    private function buildGossipEdges(int $batchSize): int
    {
        $edges = 0;
        GossipThread::orderBy('id')->chunk($batchSize, function ($threads) use (&$edges) {
            foreach ($threads as $thread) {
                $edges += GraphEdgeService::buildEdgesForGossipThread($thread);
            }
        });
        return $edges;
    }

    private function buildCreatorEdges(int $batchSize): int
    {
        $edges = 0;
        ContentDocument::whereNotNull('creator_id')
            ->orderBy('id')
            ->chunk($batchSize, function ($docs) use (&$edges) {
                foreach ($docs as $doc) {
                    $edges += GraphEdgeService::buildCreatorEdge(
                        $doc->source_type,
                        $doc->source_id,
                        $doc->creator_id
                    );
                }
            });
        return $edges;
    }
}
