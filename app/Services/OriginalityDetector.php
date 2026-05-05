<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class OriginalityDetector
{
    /**
     * Classify the post per strategy §3.3.
     *
     * @return string one of 'original'|'derivative_substantial'|'derivative_minimal'|'reused'
     */
    public static function classify(int $postId): string
    {
        $p = DB::table('posts')->where('id', $postId)->first();
        if (!$p) {
            return 'original';
        }

        // Derivative branches first.
        if (!empty($p->reply_to_post_id) || !empty($p->stitch_from_post_id) || !empty($p->quote_from_post_id)) {
            $hasNewBytes = !empty($p->content) && strlen((string) $p->content) >= 50;
            return $hasNewBytes ? 'derivative_substantial' : 'derivative_minimal';
        }

        // Heuristic: same user posting >= 4 posts/day with identical thumbnails is "reused".
        // Use raw SQL — Postgres GROUP BY semantics don't play well with Laravel's exists() wrapper.
        $row = DB::selectOne('
            SELECT 1 FROM (
                SELECT pm.thumbnail_path, COUNT(*) AS c
                FROM post_media pm
                JOIN posts p ON p.id = pm.post_id
                WHERE p.user_id = ?
                  AND pm.thumbnail_path IS NOT NULL
                  AND pm.thumbnail_path <> \'\'
                  AND p.created_at >= ?
                GROUP BY pm.thumbnail_path
                HAVING COUNT(*) >= 4
            ) x LIMIT 1
        ', [$p->user_id, now()->subDay()->toDateTimeString()]);
        $recentSameThumb = $row !== null;

        if ($recentSameThumb) {
            return 'reused';
        }

        return 'original';
    }
}
