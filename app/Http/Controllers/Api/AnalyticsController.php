<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreatorScore;
use App\Models\Post;
use App\Models\UserEngagementLevel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    public function dashboard(int $id)
    {
        $score = CreatorScore::where('user_id', $id)->first();
        $engagementLevel = UserEngagementLevel::where('user_id', $id)->first();

        // Daily metrics for last 7 days from post_views
        $dailyMetrics = DB::table('post_views')
            ->join('posts', 'posts.id', '=', 'post_views.post_id')
            ->where('posts.user_id', $id)
            ->where('post_views.created_at', '>=', Carbon::now()->subDays(7))
            ->selectRaw('DATE(post_views.created_at) as date, COUNT(*) as views')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->date,
                'views' => (int) $row->views,
            ])
            ->values()
            ->toArray();

        $totalFollowers = DB::table('user_follows')->where('following_id', $id)->count();
        $totalPosts = Post::where('user_id', $id)->count();
        $totalViews = Post::where('user_id', $id)->sum('views_count');

        return response()->json(['data' => [
            'total_followers' => $totalFollowers,
            'total_posts' => $totalPosts,
            'total_views' => (int) $totalViews,
            'total_earnings' => (float) DB::table('creator_earnings')->where('creator_id', $id)->sum('net_amount'),
            'engagement_rate' => round(($score->score ?? 0) / 100, 2),
            'engagement_level' => $engagementLevel->level ?? 'casual',
            'tier' => $score->tier ?? 'star',
            'daily_metrics' => $dailyMetrics,
        ]]);
    }

    public function postPerformance(int $id)
    {
        $posts = Post::where('user_id', $id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($p) => [
                'post_id' => $p->id,
                'content' => mb_substr($p->content ?? '', 0, 100),
                'views' => (int) ($p->views_count ?? 0),
                'likes' => (int) ($p->likes_count ?? 0),
                'comments' => (int) ($p->comments_count ?? 0),
                'shares' => (int) ($p->shares_count ?? 0),
                'engagement_rate' => round(($p->engagement_score ?? 0) / 100, 2),
                'created_at' => $p->created_at?->toISOString(),
            ]);

        return response()->json(['data' => $posts]);
    }

    public function audienceInsights(int $id)
    {
        $totalFollowers = DB::table('user_follows')->where('following_id', $id)->count();

        // Region breakdown
        $regions = DB::table('user_follows')
            ->join('user_profiles', 'user_follows.follower_id', '=', 'user_profiles.id')
            ->where('user_follows.following_id', $id)
            ->whereNotNull('user_profiles.region_name')
            ->selectRaw('user_profiles.region_name as label, COUNT(*) as count')
            ->groupBy('user_profiles.region_name')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'category' => 'region',
                'label' => $r->label,
                'value' => (string) $r->count,
                'percentage' => $totalFollowers > 0 ? round(($r->count / $totalFollowers) * 100, 1) : 0,
            ]);

        // Gender breakdown
        $genders = DB::table('user_follows')
            ->join('user_profiles', 'user_follows.follower_id', '=', 'user_profiles.id')
            ->where('user_follows.following_id', $id)
            ->whereNotNull('user_profiles.gender')
            ->selectRaw('user_profiles.gender as label, COUNT(*) as count')
            ->groupBy('user_profiles.gender')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($g) => [
                'category' => 'gender',
                'label' => $g->label,
                'value' => (string) $g->count,
                'percentage' => $totalFollowers > 0 ? round(($g->count / $totalFollowers) * 100, 1) : 0,
            ]);

        $insights = collect([
            [
                'category' => 'followers',
                'label' => 'Total Followers',
                'value' => (string) $totalFollowers,
                'percentage' => 100.0,
            ],
        ])->merge($regions)->merge($genders)->values();

        return response()->json(['data' => $insights]);
    }

    public function engagementLevel(int $id)
    {
        $level = UserEngagementLevel::where('user_id', $id)->first();

        return response()->json(['data' => [
            'level' => $level->level ?? 'casual',
            'weekly_actions' => (int) ($level->weekly_actions ?? 0),
            'streak_days' => (int) ($level->streak_days ?? 0),
        ]]);
    }
}
