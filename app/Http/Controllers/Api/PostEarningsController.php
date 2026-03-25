<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PostEarningsController extends Controller
{
    /**
     * GET /api/posts/{postId}/earnings
     *
     * Estimates creator earnings for a post based on engagement stats
     * and the active per-metric rates in `creator_earnings_rates`.
     */
    public function earnings(int $postId): JsonResponse
    {
        $post = Post::query()
            ->select([
                'id',
                'views_count',
                'likes_count',
                'shares_count',
                'saves_count',
                'comments_count',
                'watch_time_seconds',
                'impressions_count',
            ])
            ->find($postId);

        if (!$post) {
            return response()->json([
                'success' => false,
                'message' => 'Post not found',
            ], 404);
        }

        $activeRates = DB::table('creator_earnings_rates')
            ->where('is_active', true)
            ->get();

        $rateByMetric = [];
        foreach ($activeRates as $rateRow) {
            // Stored as decimal(10,4), cast to float for math + JSON.
            $rateByMetric[$rateRow->metric] = (float) $rateRow->rate;
        }

        $viewsCount = (int) ($post->views_count ?? 0);
        $likesCount = (int) ($post->likes_count ?? 0);
        $sharesCount = (int) ($post->shares_count ?? 0);
        $savesCount = (int) ($post->saves_count ?? 0);
        $commentsCount = (int) ($post->comments_count ?? 0);
        $watchTimeSeconds = (int) ($post->watch_time_seconds ?? 0);

        $impressions = (int) ($post->impressions_count ?? 0);
        if ($impressions <= 0) {
            $impressions = $viewsCount;
        }

        $viewsRate = $rateByMetric['view'] ?? 0.0;
        $likesRate = $rateByMetric['like'] ?? 0.0;
        $sharesRate = $rateByMetric['share'] ?? 0.0;
        $savesRate = $rateByMetric['save'] ?? 0.0;
        $commentsRate = $rateByMetric['comment'] ?? 0.0;
        $watchSecondRate = $rateByMetric['watch_second'] ?? 0.0;

        $viewsAmount = round($viewsCount * $viewsRate, 2);
        $likesAmount = round($likesCount * $likesRate, 2);
        $sharesAmount = round($sharesCount * $sharesRate, 2);
        $savesAmount = round($savesCount * $savesRate, 2);
        $commentsAmount = round($commentsCount * $commentsRate, 2);
        $watchTimeAmount = round($watchTimeSeconds * $watchSecondRate, 2);

        $estimatedEarnings = round(
            $viewsAmount + $likesAmount + $sharesAmount + $savesAmount + $commentsAmount + $watchTimeAmount,
            2
        );

        $engagements = $likesCount + $sharesCount + $savesCount + $commentsCount;
        $engagementRate = $impressions > 0
            ? round(($engagements / $impressions) * 100, 1)
            : 0.0;

        return response()->json([
            'success' => true,
            'data' => [
                'post_id' => (int) $post->id,
                'estimated_earnings' => $estimatedEarnings,
                'currency' => 'TSh',
                'breakdown' => [
                    'views' => [
                        'count' => $viewsCount,
                        'rate' => $viewsRate,
                        'amount' => $viewsAmount,
                    ],
                    'likes' => [
                        'count' => $likesCount,
                        'rate' => $likesRate,
                        'amount' => $likesAmount,
                    ],
                    'shares' => [
                        'count' => $sharesCount,
                        'rate' => $sharesRate,
                        'amount' => $sharesAmount,
                    ],
                    'saves' => [
                        'count' => $savesCount,
                        'rate' => $savesRate,
                        'amount' => $savesAmount,
                    ],
                    'comments' => [
                        'count' => $commentsCount,
                        'rate' => $commentsRate,
                        'amount' => $commentsAmount,
                    ],
                    'watch_time' => [
                        'seconds' => $watchTimeSeconds,
                        'rate' => $watchSecondRate,
                        'amount' => $watchTimeAmount,
                    ],
                ],
                'engagement_rate' => $engagementRate,
                'impressions' => $impressions,
            ],
        ]);
    }
}

