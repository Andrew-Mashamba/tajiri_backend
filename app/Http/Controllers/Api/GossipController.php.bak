<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GossipThread;
use App\Models\Post;
use Illuminate\Http\Request;

class GossipController extends Controller
{
    private $proverbs = [
        ["text_en" => "Patience brings good things", "text_sw" => "Subira huvuta heri"],
        ["text_en" => "Unity is strength", "text_sw" => "Umoja ni nguvu"],
        ["text_en" => "A slow person gets far", "text_sw" => "Pole pole ndio mwendo"],
        ["text_en" => "Knowledge has no weight", "text_sw" => "Elimu haina mzigo"],
        ["text_en" => "Actions speak louder than words", "text_sw" => "Maneno ni maneno, matendo ni matendo"],
        ["text_en" => "Where there is a will, there is a way", "text_sw" => "Akili ni mali"],
        ["text_en" => "A tree is known by its fruit", "text_sw" => "Mti hujulikana kwa matunda yake"],
        ["text_en" => "Character is wealth", "text_sw" => "Tabia ni mali"],
        ["text_en" => "Who sows will reap", "text_sw" => "Apandaye huchuma"],
        ["text_en" => "Time waits for no one", "text_sw" => "Wakati hausubiri mtu"],
        ["text_en" => "A person is people", "text_sw" => "Mtu ni watu"],
        ["text_en" => "One finger does not kill a louse", "text_sw" => "Kidole kimoja hakivunji chawa"],
        ["text_en" => "What is past is past", "text_sw" => "Yaliyopita si ndwele"],
        ["text_en" => "Words alone are not enough", "text_sw" => "Usiseme kwa maneno tu"],
        ["text_en" => "Good things take time", "text_sw" => "Haraka haraka haina baraka"],
        ["text_en" => "Every journey starts with a single step", "text_sw" => "Safari ya maili elfu huanza kwa hatua moja"],
        ["text_en" => "A grateful heart is a magnet for miracles", "text_sw" => "Moyo wa shukrani ni sumaku ya miujiza"],
        ["text_en" => "A bird in the hand is worth two in the bush", "text_sw" => "Bora kuku mkononi kuliko kanga porini"],
        ["text_en" => "The cow that comes early drinks clean water", "text_sw" => "Ng'ombe wa kwanza kunywa maji safi"],
        ["text_en" => "Do not count your chickens before they hatch", "text_sw" => "Usihesabu vifaranga kabla hayajatatagaliwa"],
        ["text_en" => "Work hard today for a better tomorrow", "text_sw" => "Fanya kazi leo kwa kesho bora"],
        ["text_en" => "One hand washes the other", "text_sw" => "Mkono mmoja hauoshei"],
        ["text_en" => "The tortoise wins the race", "text_sw" => "Kobe hushinda mbio"],
        ["text_en" => "He who asks, does not get lost", "text_sw" => "Aulizaye hana hasara"],
        ["text_en" => "A child who asks will learn", "text_sw" => "Mtoto aulizaye hajapotea"],
        ["text_en" => "The eye of the owner fattens the cattle", "text_sw" => "Jicho la mwenyewe linasimamia ng'ombe wake"],
        ["text_en" => "Money is not everything", "text_sw" => "Pesa si kila kitu"],
        ["text_en" => "Love conquers all", "text_sw" => "Upendo hushinda yote"],
        ["text_en" => "Tomorrow is another day", "text_sw" => "Kesho ni siku nyingine"],
        ["text_en" => "Where there is smoke, there is fire", "text_sw" => "Penye moshi pana moto"],
    ];

    public function threads(Request $request)
    {
        $query = GossipThread::with(["seedPost.user", "template"])
            ->where("status", $request->get("status", "active"))
            ->orderByDesc("velocity_score");

        if ($cat = $request->get("category")) {
            $query->where("category", $cat);
        }

        $threads = $query->paginate($request->get("per_page", 20));

        $data = $threads->getCollection()->map(function ($t) {
            return [
                "id" => $t->id,
                "seed_post_id" => $t->seed_post_id,
                "title_en" => $t->resolved_title_en,
                "title_sw" => $t->resolved_title_sw,
                "category" => $t->category,
                "velocity_score" => (float) $t->velocity_score,
                "post_count" => $t->post_count,
                "participant_count" => $t->participant_count,
                "status" => $t->status,
                "geographic_scope" => $t->geographic_scope,
                "created_at" => $t->created_at?->toIso8601String(),
                "seed_post" => $t->seedPost ? $this->formatPost($t->seedPost) : null,
            ];
        });

        return response()->json([
            "data" => $data,
            "meta" => [
                "current_page" => $threads->currentPage(),
                "last_page" => $threads->lastPage(),
                "total" => $threads->total(),
            ],
        ]);
    }

    public function show(int $id)
    {
        $thread = GossipThread::with(["template", "posts.user"])->findOrFail($id);
        $posts = $thread->posts->map(fn($p) => $this->formatPost($p));

        return response()->json([
            "data" => [
                "id" => $thread->id,
                "title_en" => $thread->resolved_title_en,
                "title_sw" => $thread->resolved_title_sw,
                "category" => $thread->category,
                "velocity_score" => (float) $thread->velocity_score,
                "post_count" => $thread->post_count,
                "participant_count" => $thread->participant_count,
                "status" => $thread->status,
                "seed_post" => $thread->seedPost ? $this->formatPost($thread->seedPost) : null,
                "posts" => $posts,
            ],
        ]);
    }

    public function digest(Request $request)
    {
        $threads = GossipThread::with(["seedPost.user", "template"])
            ->where("status", "active")
            ->orderByDesc("velocity_score")
            ->limit(5)
            ->get()
            ->map(function ($t) {
                return [
                    "id" => $t->id,
                    "seed_post_id" => $t->seed_post_id,
                    "title_en" => $t->resolved_title_en,
                    "title_sw" => $t->resolved_title_sw,
                    "category" => $t->category,
                    "velocity_score" => (float) $t->velocity_score,
                    "post_count" => $t->post_count,
                    "participant_count" => $t->participant_count,
                    "status" => $t->status,
                    "created_at" => $t->created_at?->toIso8601String(),
                    "seed_post" => $t->seedPost ? $this->formatPost($t->seedPost) : null,
                ];
            });

        $proverb = $this->proverbs[array_rand($this->proverbs)];

        return response()->json([
            "data" => [
                "threads" => $threads,
                "proverb" => $proverb,
            ],
        ]);
    }

    public function personalizedFeed(Request $request)
    {
        // For now: return the same as for-you feed but attach thread info
        $posts = Post::with(["user", "media"])
            ->where("status", "published")
            ->orderByDesc("created_at")
            ->paginate($request->get("per_page", 20));

        // Attach thread info to posts
        $postIds = $posts->getCollection()->pluck("id")->toArray();
        $threadMap = \DB::table("gossip_thread_posts")
            ->join("gossip_threads", "gossip_threads.id", "=", "gossip_thread_posts.thread_id")
            ->leftJoin("thread_title_templates", "thread_title_templates.key", "=", "gossip_threads.title_key")
            ->whereIn("gossip_thread_posts.post_id", $postIds)
            ->where("gossip_threads.status", "active")
            ->select(
                "gossip_thread_posts.post_id",
                "gossip_threads.id as thread_id",
                "thread_title_templates.template_en",
                "thread_title_templates.template_sw",
                "gossip_threads.title_slots"
            )
            ->get()
            ->keyBy("post_id");

        $data = $posts->getCollection()->map(function ($post) use ($threadMap) {
            $p = $this->formatPost($post);
            if (isset($threadMap[$post->id])) {
                $tm = $threadMap[$post->id];
                $p["thread_id"] = $tm->thread_id;
                $slots = json_decode($tm->title_slots ?? "{}", true) ?: [];
                $titleEn = $tm->template_en ?? "Trending";
                $titleSw = $tm->template_sw ?? "Vinavyoongezeka";
                foreach ($slots as $k => $v) {
                    $titleEn = str_replace("{{$k}}", $v, $titleEn);
                    $titleSw = str_replace("{{$k}}", $v, $titleSw);
                }
                $p["thread_title"] = $titleEn;
            }
            return $p;
        });

        return response()->json([
            "data" => $data,
            "meta" => [
                "current_page" => $posts->currentPage(),
                "last_page" => $posts->lastPage(),
                "total" => $posts->total(),
            ],
        ]);
    }

    private function formatPost($post): array
    {
        return [
            "id" => $post->id,
            "user_id" => $post->user_id,
            "content" => $post->content,
            "type" => $post->type ?? "text",
            "likes_count" => $post->likes_count ?? 0,
            "comments_count" => $post->comments_count ?? 0,
            "shares_count" => $post->shares_count ?? 0,
            "created_at" => $post->created_at?->toIso8601String(),
            "updated_at" => $post->updated_at?->toIso8601String(),
            "user" => $post->user ? [
                "id" => $post->user->id,
                "name" => $post->user->name,
                "avatar_url" => $post->user->avatar_url ?? null,
            ] : null,
            "media" => $post->media ? $post->media->map(fn($m) => [
                "id" => $m->id,
                "url" => $m->url,
                "type" => $m->type,
            ])->toArray() : [],
        ];
    }
}
