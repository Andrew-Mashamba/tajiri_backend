<?php

namespace App\Services\ContentEngine;

use App\Models\ContentDocument;
use App\Models\ContentGraphEdge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GraphEdgeService
{
    /**
     * Build all graph edges for a single post.
     * Called by observer on post create and by backfill command.
     */
    public static function buildEdgesForPost(\App\Models\Post $post): int
    {
        $edges = 0;
        $docId = self::getDocId('post', $post->id);
        if (!$docId) return 0;

        // CREATOR_OF: creator -> doc
        $edges += self::upsertEdge('creator', $post->user_id, 'doc', $docId, ContentGraphEdge::EDGE_CREATOR_OF, 1.0);

        // SHARED: sharer -> original
        if ($post->original_post_id) {
            $originalDocId = self::getDocId('post', $post->original_post_id);
            if ($originalDocId) {
                $edges += self::upsertEdge('doc', $docId, 'doc', $originalDocId, ContentGraphEdge::EDGE_SHARED, 3.0);
            }
        }

        // REPLIED_TO: reply -> parent
        if ($post->reply_to_post_id) {
            $parentDocId = self::getDocId('post', $post->reply_to_post_id);
            if ($parentDocId) {
                $edges += self::upsertEdge('doc', $docId, 'doc', $parentDocId, ContentGraphEdge::EDGE_REPLIED_TO, 2.5);
            }
        }

        // STITCHED: stitch -> original
        if ($post->stitch_from_post_id) {
            $stitchDocId = self::getDocId('post', $post->stitch_from_post_id);
            if ($stitchDocId) {
                $edges += self::upsertEdge('doc', $docId, 'doc', $stitchDocId, ContentGraphEdge::EDGE_STITCHED, 2.0);
            }
        }

        // MENTIONED_CREATOR: post -> mentioned creator
        if ($post->tagged_users) {
            $taggedIds = is_array($post->tagged_users) ? $post->tagged_users : json_decode($post->tagged_users, true);
            if (is_array($taggedIds)) {
                foreach (array_slice($taggedIds, 0, 10) as $mentionedId) {
                    $edges += self::upsertEdge('doc', $docId, 'creator', (int) $mentionedId, ContentGraphEdge::EDGE_MENTIONED_CREATOR, 1.5);
                }
            }
        }

        // HASHTAG_CO_OCCURRENCE: find other recent posts with same hashtags
        if ($post->content_tags) {
            $tags = is_array($post->content_tags) ? $post->content_tags : json_decode($post->content_tags, true);
            if (is_array($tags) && !empty($tags)) {
                $edges += self::buildHashtagCoOccurrence($docId, $tags, $post->id);
            }
        }

        return $edges;
    }

    /**
     * Build edges for a clip (shares via original_clip_id).
     */
    public static function buildEdgesForClip(\App\Models\Clip $clip): int
    {
        $edges = 0;
        $docId = self::getDocId('clip', $clip->id);
        if (!$docId) return 0;

        // CREATOR_OF
        $edges += self::upsertEdge('creator', $clip->user_id, 'doc', $docId, ContentGraphEdge::EDGE_CREATOR_OF, 1.0);

        // SHARED (clip share)
        if ($clip->original_clip_id) {
            $originalDocId = self::getDocId('clip', $clip->original_clip_id);
            if ($originalDocId) {
                $edges += self::upsertEdge('doc', $docId, 'doc', $originalDocId, ContentGraphEdge::EDGE_SHARED, 3.0);
            }
        }

        return $edges;
    }

    /**
     * Build SAME_THREAD edges for posts in a gossip thread.
     */
    public static function buildEdgesForGossipThread(\App\Models\GossipThread $thread): int
    {
        $edges = 0;

        $seedDocId = self::getDocId('post', $thread->seed_post_id);
        if (!$seedDocId) return 0;

        // Link the gossip_thread document to the seed post
        $threadDocId = self::getDocId('gossip_thread', $thread->id);
        if ($threadDocId) {
            $edges += self::upsertEdge('doc', $threadDocId, 'doc', $seedDocId, ContentGraphEdge::EDGE_SAME_THREAD, 1.0);
        }

        return $edges;
    }

    /**
     * Build CREATOR_OF edge for any content type.
     */
    public static function buildCreatorEdge(string $sourceType, int $sourceId, int $creatorId): int
    {
        $docId = self::getDocId($sourceType, $sourceId);
        if (!$docId) return 0;

        return self::upsertEdge('creator', $creatorId, 'doc', $docId, ContentGraphEdge::EDGE_CREATOR_OF, 1.0);
    }

    /**
     * Build FOLLOWED_THEN_CREATED edge (content earned a follow).
     */
    public static function buildFollowedThenCreated(int $docId, int $creatorId): int
    {
        return self::upsertEdge('doc', $docId, 'creator', $creatorId, ContentGraphEdge::EDGE_FOLLOWED_THEN_CREATED, 2.0);
    }

    /**
     * Build hashtag co-occurrence edges.
     */
    private static function buildHashtagCoOccurrence(int $docId, array $tags, int $postId): int
    {
        $edges = 0;

        $tagsJson = json_encode(array_values($tags));
        $recentDocs = DB::select("
            SELECT id, hashtags FROM content_documents
            WHERE id != ? AND published_at >= NOW() - INTERVAL '7 days'
              AND hashtags IS NOT NULL
              AND EXISTS (
                SELECT 1 FROM jsonb_array_elements_text(hashtags) AS h
                WHERE h = ANY(SELECT jsonb_array_elements_text(?::jsonb))
              )
            LIMIT 50
        ", [$docId, $tagsJson]);

        foreach ($recentDocs as $otherDoc) {
            $otherTags = json_decode($otherDoc->hashtags, true) ?? [];
            $overlap = array_intersect($tags, $otherTags);
            if (!empty($overlap)) {
                $weight = min(2.0, 0.5 * count($overlap));
                $edges += self::upsertEdge('doc', $docId, 'doc', $otherDoc->id, ContentGraphEdge::EDGE_HASHTAG_CO_OCCURRENCE, $weight);
                if ($edges >= 5) break;
            }
        }

        return $edges;
    }

    /**
     * Get the content_documents.id for a source_type + source_id.
     */
    private static function getDocId(string $sourceType, int $sourceId): ?int
    {
        return ContentDocument::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->value('id');
    }

    /**
     * Upsert a graph edge (insert or ignore on unique constraint).
     * Returns 1 if inserted, 0 if already existed.
     */
    private static function upsertEdge(
        string $sourceType,
        int $sourceId,
        string $targetType,
        int $targetId,
        string $edgeType,
        float $weight
    ): int {
        try {
            $edge = ContentGraphEdge::firstOrCreate(
                [
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'edge_type' => $edgeType,
                ],
                [
                    'weight' => $weight,
                    'created_at' => now(),
                ]
            );
            return $edge->wasRecentlyCreated ? 1 : 0;
        } catch (\Throwable $e) {
            Log::warning("GraphEdgeService: edge upsert failed", [
                'edge' => "{$sourceType}:{$sourceId} -> {$targetType}:{$targetId} ({$edgeType})",
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }
}
