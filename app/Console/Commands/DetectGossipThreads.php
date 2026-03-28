<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\GossipThread;
use App\Models\GossipThreadPost;
use App\Models\ThreadTitleTemplate;
use App\Models\Post;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DetectGossipThreads extends Command
{
    protected $signature = "gossip:detect";
    protected $description = "Detect trending posts and create gossip threads";

    public function handle(): int
    {
        $since = Carbon::now()->subHours(6);
        $newThreads = 0;
        $updatedThreads = 0;
        $archivedThreads = 0;

        // Step 1: Calculate velocity for recent posts
        // Exclude posts from users who have opted out of thread detection
        $posts = Post::where("created_at", ">=", $since)
            ->whereIn("status", ["published", "active"])
            ->whereNotIn('user_id', function($q) {
                $q->select('id')->from('user_profiles')->where('opt_out_threads', true);
            })
            ->get()
            ->map(function ($p) {
                $hours = max(Carbon::now()->diffInMinutes($p->created_at) / 60, 0.1);
                $p->velocity = (($p->likes_count ?? 0) + ($p->comments_count ?? 0) * 2 + ($p->shares_count ?? 0) * 3) / $hours;
                return $p;
            });

        // Step 2: Flag posts with velocity > 2x creator average
        $flagged = $posts->filter(function ($p) {
            $avg = Post::where("user_id", $p->user_id)
                ->where("created_at", ">=", Carbon::now()->subDays(30))
                ->avg(DB::raw("(likes_count + comments_count * 2 + shares_count * 3)")) ?? 0;
            $avgVelocity = $avg > 0 ? $avg / 24 : 1;
            return $p->velocity > $avgVelocity * 2;
        });

        // Step 3: Group by shared hashtags
        $postHashtags = [];
        foreach ($flagged as $p) {
            preg_match_all("/#(\w+)/u", $p->content ?? "", $matches);
            $postHashtags[$p->id] = array_map("strtolower", $matches[1] ?? []);
        }

        // Find groups with 2+ common hashtags
        $visited = [];
        $groups = [];
        foreach ($postHashtags as $pid1 => $tags1) {
            if (in_array($pid1, $visited)) continue;
            $group = [$pid1];
            $visited[] = $pid1;
            foreach ($postHashtags as $pid2 => $tags2) {
                if ($pid1 === $pid2 || in_array($pid2, $visited)) continue;
                $common = array_intersect($tags1, $tags2);
                if (count($common) >= 2) {
                    $group[] = $pid2;
                    $visited[] = $pid2;
                }
            }
            if (count($group) >= 3) {
                $groups[] = $group;
            }
        }

        // Step 3b: Create threads for groups
        foreach ($groups as $groupPostIds) {
            // Check if posts already in an active thread
            $existing = GossipThreadPost::whereIn("post_id", $groupPostIds)
                ->whereHas("thread", fn($q) => $q->where("status", "active"))
                ->exists();
            if ($existing) continue;

            $groupPosts = $posts->whereIn("id", $groupPostIds);
            $seedPost = $groupPosts->sortByDesc("velocity")->first();
            if (!$seedPost) continue;

            // Pick a template
            $allTags = [];
            foreach ($groupPostIds as $pid) {
                $allTags = array_merge($allTags, $postHashtags[$pid] ?? []);
            }
            $tagCounts = array_count_values($allTags);
            arsort($tagCounts);
            $topTag = array_key_first($tagCounts) ?? "general";

            $categoryMap = [
                "music" => "music", "bongo" => "music", "flava" => "music", "wimbo" => "music",
                "sport" => "sports", "mchezo" => "sports", "goal" => "sports", "simba" => "sports", "yanga" => "sports",
                "biashara" => "business", "business" => "business", "market" => "business", "soko" => "business",
                "burudani" => "entertainment", "entertainment" => "entertainment", "movie" => "entertainment",
            ];
            $category = "general";
            foreach ($tagCounts as $tag => $cnt) {
                if (isset($categoryMap[$tag])) {
                    $category = $categoryMap[$tag];
                    break;
                }
            }

            $template = ThreadTitleTemplate::where("category", $category)
                ->where("is_active", true)
                ->inRandomOrder()
                ->first() ?? ThreadTitleTemplate::where("is_active", true)->inRandomOrder()->first();

            $titleSlots = ["topic" => ucfirst($topTag), "count" => (string) count($groupPostIds), "category" => ucfirst($category)];

            $thread = GossipThread::create([
                "seed_post_id" => $seedPost->id,
                "title_key" => $template?->key,
                "title_slots" => $titleSlots,
                "category" => $category,
                "velocity_score" => $groupPosts->avg("velocity"),
                "post_count" => count($groupPostIds),
                "participant_count" => $groupPosts->pluck("user_id")->unique()->count(),
                "status" => "active",
            ]);

            foreach ($groupPostIds as $pid) {
                GossipThreadPost::create([
                    "thread_id" => $thread->id,
                    "post_id" => $pid,
                    "relevance_score" => $posts->firstWhere("id", $pid)?->velocity ?? 0,
                    "added_at" => now(),
                ]);
            }
            $newThreads++;
        }

        // Step 4: Update existing active threads
        $activeThreads = GossipThread::where("status", "active")->get();
        foreach ($activeThreads as $thread) {
            $avgVelocity = $thread->posts()
                ->get()
                ->map(function ($p) {
                    $hours = max(Carbon::now()->diffInMinutes($p->created_at) / 60, 0.1);
                    return (($p->likes_count ?? 0) + ($p->comments_count ?? 0) * 2 + ($p->shares_count ?? 0) * 3) / $hours;
                })
                ->avg() ?? 0;

            $thread->velocity_score = $avgVelocity;
            $thread->post_count = $thread->threadPosts()->count();
            $thread->participant_count = $thread->posts()->distinct("user_id")->count("user_id");

            if ($avgVelocity < 10 && !$thread->cooling_since) {
                $thread->cooling_since = now();
            }

            if ($thread->cooling_since && $thread->cooling_since->lt(Carbon::now()->subHours(6))) {
                $thread->status = "cooling";
            }

            $thread->save();
            $updatedThreads++;
        }

        // Archive cooling threads after 48h
        $archived = GossipThread::where("status", "cooling")
            ->where("cooling_since", "<", Carbon::now()->subHours(48))
            ->update(["status" => "archived"]);
        $archivedThreads = $archived;

        // Step 5: Add new high-velocity posts to existing threads
        foreach ($activeThreads as $thread) {
            $threadTags = [];
            foreach ($thread->posts as $tp) {
                preg_match_all("/#(\w+)/u", $tp->content ?? "", $m);
                $threadTags = array_merge($threadTags, array_map("strtolower", $m[1] ?? []));
            }
            $threadTags = array_unique($threadTags);

            foreach ($flagged as $fp) {
                if ($thread->threadPosts()->where("post_id", $fp->id)->exists()) continue;
                $fpTags = $postHashtags[$fp->id] ?? [];
                $common = array_intersect($fpTags, $threadTags);
                if (count($common) >= 2) {
                    GossipThreadPost::create([
                        "thread_id" => $thread->id,
                        "post_id" => $fp->id,
                        "relevance_score" => $fp->velocity ?? 0,
                        "added_at" => now(),
                    ]);
                    $thread->increment("post_count");
                }
            }
        }

        // Step 6: Viral chain detection — posts with 50+ shares in the last hour
        $oneHourAgo = Carbon::now()->subHour();

        // Find post IDs already in active threads
        $alreadyInThread = GossipThreadPost::whereHas("thread", fn($q) => $q->where("status", "active"))
            ->pluck("post_id")
            ->toArray();

        $viralPosts = Post::whereNotIn("id", $alreadyInThread)
            ->whereIn("status", ["published", "active"])
            ->where("created_at", ">=", $oneHourAgo)
            ->where("shares_count", ">=", 50)
            ->get();

        foreach ($viralPosts as $vPost) {
            $snippet = \Str::limit($vPost->content ?? "Trending content", 50);
            $viralTitle = "Going Viral: {$snippet}";

            $thread = GossipThread::create([
                "seed_post_id" => $vPost->id,
                "title_key" => null,
                "title_slots" => ["title" => $viralTitle],
                "category" => "viral",
                "velocity_score" => $vPost->shares_count ?? 0,
                "post_count" => 1,
                "participant_count" => 1,
                "status" => "active",
            ]);

            GossipThreadPost::create([
                "thread_id" => $thread->id,
                "post_id" => $vPost->id,
                "relevance_score" => $vPost->shares_count ?? 0,
                "added_at" => now(),
            ]);

            $newThreads++;
        }

        $this->info("Gossip detection: {$newThreads} new, {$updatedThreads} updated, {$archivedThreads} archived");
        return Command::SUCCESS;
    }
}
