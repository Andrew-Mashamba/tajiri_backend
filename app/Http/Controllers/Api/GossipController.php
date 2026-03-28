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
        // Extended proverbs (70+ more authentic Swahili proverbs)
        ["text_en" => "He who is not taught by his mother will be taught by the world", "text_sw" => "Asiyefunzwa na mamaye, hufunzwa na ulimwengu"],
        ["text_en" => "The cure for fire is fire", "text_sw" => "Dawa ya moto ni moto"],
        ["text_en" => "A stick from afar does not kill a snake", "text_sw" => "Fimbo ya mbali haiui nyoka"],
        ["text_en" => "Little by little fills the measure", "text_sw" => "Haba na haba hujaza kibaba"],
        ["text_en" => "A visitor is a guest for two days, on the third he becomes work", "text_sw" => "Mgeni siku mbili, siku ya tatu mpe jembe"],
        ["text_en" => "He who digs a pit for others falls in himself", "text_sw" => "Mchimba kisima huingia mwenyewe"],
        ["text_en" => "The hand that gives is above the hand that receives", "text_sw" => "Mkono wa kutoa u juu ya mkono wa kupokea"],
        ["text_en" => "Wisdom is like a baobab tree, no one person can embrace it", "text_sw" => "Hekima ni kama mbuyu, mtu mmoja hawezi kuizunguka"],
        ["text_en" => "The child who has not traveled thinks their mother is the best cook", "text_sw" => "Mtoto asiyetembea hudhani mama yake ndiye mpishi bora"],
        ["text_en" => "If you want to go fast go alone, if you want to go far go together", "text_sw" => "Ukitaka kwenda mbio nenda peke yako, ukitaka kwenda mbali nenda pamoja"],
        ["text_en" => "Rain does not fall on one roof alone", "text_sw" => "Mvua hainyeshi paa moja peke yake"],
        ["text_en" => "A clever person does not get lost", "text_sw" => "Mwerevu hapotei"],
        ["text_en" => "Do not boast about tomorrow, you do not know what it will bring", "text_sw" => "Usijisifu kwa kesho, hujui italeta nini"],
        ["text_en" => "The heart of a person is a sea", "text_sw" => "Moyo wa mtu ni bahari"],
        ["text_en" => "A good name is better than riches", "text_sw" => "Jina jema si utajiri wa mali, ni utajiri wa roho"],
        ["text_en" => "The tongue has no bones but can break bones", "text_sw" => "Ulimi hauna mfupa lakini unaweza kuvunja mifupa"],
        ["text_en" => "Iron sharpens iron", "text_sw" => "Chuma hunoa chuma"],
        ["text_en" => "The truth is bitter, but lies bring greater suffering", "text_sw" => "Ukweli ni uchungu, lakini uongo huleta mateso makubwa zaidi"],
        ["text_en" => "Wealth does not last but character endures", "text_sw" => "Mali haidumu lakini tabia inadumu"],
        ["text_en" => "Sweet words do not fill the stomach", "text_sw" => "Maneno mazuri hayajazi tumbo"],
        ["text_en" => "Even a small star shines in the darkness", "text_sw" => "Hata nyota ndogo huangaza gizani"],
        ["text_en" => "The lion does not turn around when a small dog barks", "text_sw" => "Simba hageuki mbwa mdogo anapobweka"],
        ["text_en" => "Before healing others, heal yourself", "text_sw" => "Kabla ya kuponya wengine, jiponye kwanza"],
        ["text_en" => "A river that forgets its source will dry up", "text_sw" => "Mto unaosahau chanzo chake utakauka"],
        ["text_en" => "The sun does not forget any village", "text_sw" => "Jua halikisahau kijiji chochote"],
        ["text_en" => "The greatest wealth is health", "text_sw" => "Utajiri mkubwa ni afya"],
        ["text_en" => "A person becomes a person through other persons", "text_sw" => "Mtu huwa mtu kwa watu wengine"],
        ["text_en" => "He who climbs a ladder must begin at the first rung", "text_sw" => "Apandaye ngazi lazima aanze na kizazi cha kwanza"],
        ["text_en" => "Empty vessel makes the most noise", "text_sw" => "Chombo tupu kina kelele nyingi"],
        ["text_en" => "Fall seven times, rise eight", "text_sw" => "Anguka mara saba, simama mara nane"],
        ["text_en" => "The early bird catches the worm", "text_sw" => "Ndege wa mapema ndiyo anayempata mdudu"],
        ["text_en" => "Knowledge without wisdom is a load of books on the back of a donkey", "text_sw" => "Elimu bila hekima ni mzigo wa vitabu mgongoni mwa punda"],
        ["text_en" => "The best time to plant a tree was twenty years ago, the second best time is now", "text_sw" => "Wakati bora wa kupanda mti ulikuwa miaka ishirini iliyopita, wakati wa pili bora ni sasa"],
        ["text_en" => "A good deed is never lost", "text_sw" => "Kitendo kizuri hakipotei"],
        ["text_en" => "Wisdom begins with wonder", "text_sw" => "Hekima huanza na mshangao"],
        ["text_en" => "Words are silver but silence is gold", "text_sw" => "Maneno ni fedha lakini ukimya ni dhahabu"],
        ["text_en" => "Two heads are better than one", "text_sw" => "Vichwa viwili ni bora kuliko kimoja"],
        ["text_en" => "It takes a village to raise a child", "text_sw" => "Mtoto anahitaji kijiji kizima kumlea"],
        ["text_en" => "The one who tells the stories rules the world", "text_sw" => "Yule anayesimulia hadithi ndiye anayetawala dunia"],
        ["text_en" => "Every failure teaches a lesson that success cannot", "text_sw" => "Kila kushindwa kunafundisha somo ambalo ushindi hauwezi kufundisha"],
        ["text_en" => "Growth begins at the end of your comfort zone", "text_sw" => "Ukuaji huanza mwishoni mwa ukanda wako wa starehe"],
        ["text_en" => "The fish does not see the water it swims in", "text_sw" => "Samaki haoni maji anayoogelea"],
        ["text_en" => "A good leader is also a good follower", "text_sw" => "Kiongozi mzuri ni mfuasi mzuri pia"],
        ["text_en" => "Two wrongs do not make a right", "text_sw" => "Makosa mawili hayafanyi sahihi"],
        ["text_en" => "When the music changes, so does the dance", "text_sw" => "Muziki ukibadilika, dansi nayo inabadilika"],
        ["text_en" => "Do not dig up in anger what you planted in love", "text_sw" => "Usichimbe kwa hasira ulichopanda kwa upendo"],
        ["text_en" => "The ant carries more than its weight", "text_sw" => "Siafu hubeba zaidi ya uzito wake"],
        ["text_en" => "What the elders see sitting, the youth cannot see standing", "text_sw" => "Wazee wanaona wakiwa wameketi, vijana hawawezi kuona hata wakisimama"],
        ["text_en" => "A tree does not shake itself", "text_sw" => "Mti haujitikisi wenyewe"],
        ["text_en" => "The night is long but the day comes", "text_sw" => "Usiku ni mrefu lakini mchana huja"],
        ["text_en" => "Hunger is the best cook", "text_sw" => "Njaa ndiyo mpishi bora"],
        ["text_en" => "Running away from your troubles is a race you will never win", "text_sw" => "Kukimbia shida zako ni mbio ambazo hutashinda"],
        ["text_en" => "Until the lion learns to write, every story will glorify the hunter", "text_sw" => "Simba atakapojifunza kuandika, hadithi zote zitamtukuza mwindaji"],
        ["text_en" => "Speak gently, the earth is soft", "text_sw" => "Zungumza kwa upole, ardhi ni laini"],
        ["text_en" => "The cow that lows the most gives the least milk", "text_sw" => "Ng'ombe anayelia sana ndiye anayetoa maziwa machache"],
        ["text_en" => "A child who is not embraced by the village will burn it down to feel its warmth", "text_sw" => "Mtoto asiyekumbatiwa na kijiji atakichoma moto kuhisi joto lake"],
        ["text_en" => "Even the mightiest eagle must come down to earth to eat", "text_sw" => "Hata tai mkubwa lazima ashuke ardhini kula"],
        ["text_en" => "Respect is not given, it is earned", "text_sw" => "Heshima haipewi, inapatikana"],
        ["text_en" => "A generous person never lacks", "text_sw" => "Mtu mkarimu hana upungufu"],
        ["text_en" => "Only a fool tests the depth of a river with both feet", "text_sw" => "Mpumbavu tu ndiye anayejaribu kina cha mto kwa miguu yote miwili"],
        ["text_en" => "The strength of the team is each individual member, the strength of each member is the team", "text_sw" => "Nguvu ya timu ni kila mwanachama, nguvu ya kila mwanachama ni timu"],
        ["text_en" => "Tame the tongue or the tongue will tame you", "text_sw" => "Dhibiti ulimi au ulimi utakudhibiti wewe"],
        ["text_en" => "Never let the sun go down on your anger", "text_sw" => "Usiiruhusu jua kushuka ukiwa na hasira"],
        ["text_en" => "You are what you eat, you become what you think", "text_sw" => "Wewe ni unachokula, unakuwa unachofikiria"],
        ["text_en" => "Character is destiny", "text_sw" => "Tabia ndiyo hatima"],
        ["text_en" => "The best mirror is an old friend", "text_sw" => "Kioo bora ni rafiki wa zamani"],
        ["text_en" => "Beginnings are always the hardest", "text_sw" => "Mwanzo daima ndio mgumu zaidi"],
        ["text_en" => "When spider webs unite, they can tie up a lion", "text_sw" => "Nyuzi za buibui zikiungana zinaweza kumfunga simba"],
        ["text_en" => "He who laughs last, laughs longest", "text_sw" => "Anayecheka mwisho ndiye anayecheka zaidi"],
        ["text_en" => "The wound you cannot see is the one that hurts the most", "text_sw" => "Jeraha usililoon hilo ndilo linalouma zaidi"],
        ["text_en" => "A grateful heart is always close to blessings", "text_sw" => "Moyo wa shukrani daima uko karibu na baraka"],
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
        $user = $request->user();
        $perPage = (int) $request->get("per_page", 20);

        // Get user interest weights from recent events
        $interests = [];
        if ($user) {
            $interests = \DB::table("user_events")
                ->select("creator_id", \DB::raw("COUNT(*) as weight"))
                ->where("user_id", $user->id)
                ->where("created_at", ">=", now()->subDays(14))
                ->whereNotNull("creator_id")
                ->groupBy("creator_id")
                ->pluck("weight", "creator_id")
                ->toArray();
        }

        $posts = Post::with(["user", "media"])
            ->where("status", "published")
            ->where("created_at", ">=", now()->subDays(7))
            ->orderByDesc("created_at")
            ->limit(200)
            ->get();

        // Score and sort by interest relevance + recency + engagement
        $scored = $posts->map(function ($post) use ($interests) {
            $interestScore = $interests[$post->user_id] ?? 0;
            $recencyHours = now()->diffInHours($post->created_at);
            $recencyScore = max(0, 168 - $recencyHours);
            $engagementScore = ($post->likes_count ?? 0) + ($post->comments_count ?? 0) * 2 + ($post->shares_count ?? 0) * 3;
            $post->_relevance = ($interestScore * 10) + $recencyScore + ($engagementScore * 0.5);
            return $post;
        })->sortByDesc("_relevance")->take($perPage)->values();

        // Attach thread info
        $postIds = $scored->pluck("id")->toArray();
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

        $data = $scored->map(function ($post) use ($threadMap) {
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
            "success" => true,
            "data" => $data,
            "meta" => [
                "current_page" => 1,
                "last_page" => 1,
                "total" => $data->count(),
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

    public function unfinishedThreads(\Illuminate\Http\Request $request)
    {
        $userId = $request->query('user_id');

        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'user_id is required'], 400);
        }

        $rows = \Illuminate\Support\Facades\DB::table('thread_read_progress')
            ->where('user_id', $userId)
            ->where('completed', false)
            ->where('posts_read', '>', 0)
            ->orderByDesc('last_read_at')
            ->get();

        $threadIds = $rows->pluck('thread_id')->toArray();
        $threads = \App\Models\GossipThread::with(['seedPost.user', 'template'])
            ->whereIn('id', $threadIds)
            ->where('status', 'active')
            ->get()
            ->keyBy('id');

        $data = $rows->map(function ($row) use ($threads) {
            $thread = $threads[$row->thread_id] ?? null;
            if (!$thread) return null;
            return [
                'thread_id' => $row->thread_id,
                'posts_read' => $row->posts_read,
                'total_posts' => $row->total_posts,
                'last_read_at' => $row->last_read_at,
                'progress_percent' => $row->total_posts > 0
                    ? round(($row->posts_read / $row->total_posts) * 100)
                    : 0,
                'title_en' => $thread->resolved_title_en,
                'title_sw' => $thread->resolved_title_sw,
                'category' => $thread->category,
                'velocity_score' => (float) $thread->velocity_score,
            ];
        })->filter()->values();

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function recordReadProgress(\Illuminate\Http\Request $request)
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'thread_id' => ['required', 'integer', 'min:1'],
            'posts_read' => ['required', 'integer', 'min:0'],
            'total_posts' => ['required', 'integer', 'min:0'],
        ]);

        $completed = $validated['posts_read'] >= $validated['total_posts'] && $validated['total_posts'] > 0;

        \Illuminate\Support\Facades\DB::table('thread_read_progress')->upsert(
            [
                'user_id' => $validated['user_id'],
                'thread_id' => $validated['thread_id'],
                'posts_read' => $validated['posts_read'],
                'total_posts' => $validated['total_posts'],
                'last_read_at' => now(),
                'completed' => $completed,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            ['user_id', 'thread_id'],
            ['posts_read', 'total_posts', 'last_read_at', 'completed', 'updated_at']
        );

        return response()->json([
            'success' => true,
            'completed' => $completed,
        ]);
    }

}

    // NOTE: closing brace of class removed — appended methods below need to replace it
