<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ThreadTitleTemplate;

class AdditionalThreadTitleTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            // General - hot
            ["key" => "gen_debate_topic", "template_en" => "The {topic} debate everyone is talking about", "template_sw" => "Mjadala wa {topic} unaozungumzwa na kila mtu", "slots" => ["topic"], "category" => "general", "tone" => "hot"],
            ["key" => "gen_bombshell", "template_en" => "{creator} drops a bombshell about {topic}", "template_sw" => "{creator} atoa habari za kushangaza kuhusu {topic}", "slots" => ["creator", "topic"], "category" => "general", "tone" => "hot"],
            ["key" => "gen_breaking_records", "template_en" => "Why {topic} is breaking records right now", "template_sw" => "Kwa nini {topic} inavunja rekodi sasa hivi", "slots" => ["topic"], "category" => "general", "tone" => "hot"],
            ["key" => "gen_no_one_talking", "template_en" => "Nobody is talking about {topic} — but they should be", "template_sw" => "Hakuna anayezungumza kuhusu {topic} — lakini wanapaswa", "slots" => ["topic"], "category" => "general", "tone" => "hot"],
            ["key" => "gen_unexpected", "template_en" => "{creator} did something unexpected with {topic}", "template_sw" => "{creator} amefanya kitu kisichotarajiwa na {topic}", "slots" => ["creator", "topic"], "category" => "general", "tone" => "hot"],
            ["key" => "gen_exposed", "template_en" => "{topic} exposed — what everyone missed", "template_sw" => "{topic} imefichuka — kile ambacho kila mtu alikosa", "slots" => ["topic"], "category" => "general", "tone" => "breaking"],
            ["key" => "gen_under_radar", "template_en" => "This {topic} story flew under the radar", "template_sw" => "Hadithi hii ya {topic} ilipita bila kutambuliwa", "slots" => ["topic"], "category" => "general", "tone" => "hot"],
            ["key" => "gen_opinion_split", "template_en" => "{topic} has opinions split right down the middle", "template_sw" => "{topic} imegawanya maoni mara mbili", "slots" => ["topic"], "category" => "general", "tone" => "battle"],
            ["key" => "gen_truth_revealed", "template_en" => "The truth about {topic} finally revealed", "template_sw" => "Ukweli kuhusu {topic} umefichuliwa hatimaye", "slots" => ["topic"], "category" => "general", "tone" => "breaking"],
            ["key" => "gen_gone_viral", "template_en" => "{topic} just went viral and here is why", "template_sw" => "{topic} imekuwa ya kupaa na hapa ndio sababu", "slots" => ["topic"], "category" => "general", "tone" => "hot"],

            // Entertainment
            ["key" => "ent_collab_drop", "template_en" => "{creator_a} and {creator_b} just dropped a surprise collab", "template_sw" => "{creator_a} na {creator_b} wametoa ushirikiano wa mshangao", "slots" => ["creator_a", "creator_b"], "category" => "entertainment", "tone" => "breaking"],
            ["key" => "ent_beef_exposed", "template_en" => "The beef between {creator_a} and {creator_b} just got real", "template_sw" => "Ugomvi kati ya {creator_a} na {creator_b} umekuwa wa kweli", "slots" => ["creator_a", "creator_b"], "category" => "entertainment", "tone" => "battle"],
            ["key" => "ent_comeback", "template_en" => "{creator} is back and people have feelings", "template_sw" => "{creator} amerudi na watu wana hisia", "slots" => ["creator"], "category" => "entertainment", "tone" => "hot"],
            ["key" => "ent_flop_alert", "template_en" => "Flop alert: {topic} did not land the way they expected", "template_sw" => "Tahadhari ya kushindwa: {topic} haikufanikiwa kama walivyotarajia", "slots" => ["topic"], "category" => "entertainment", "tone" => "hot"],
            ["key" => "ent_tea_spilled", "template_en" => "Someone just spilled the tea on {topic}", "template_sw" => "Mtu amemwaga siri kuhusu {topic}", "slots" => ["topic"], "category" => "entertainment", "tone" => "hot"],
            ["key" => "ent_cancelled", "template_en" => "Is {creator} getting cancelled? Here is what happened", "template_sw" => "Je, {creator} anafutwa? Hapa ndio kilichotokea", "slots" => ["creator"], "category" => "entertainment", "tone" => "breaking"],
            ["key" => "ent_era_defined", "template_en" => "{topic} is defining this era — agree or disagree?", "template_sw" => "{topic} inafafanua enzi hii — unakubaliana au hapana?", "slots" => ["topic"], "category" => "entertainment", "tone" => "battle"],

            // Music
            ["key" => "music_banger_alert", "template_en" => "Banger alert: {track} by {artist} is unstoppable", "template_sw" => "Wimbo bora: {track} na {artist} haustopiki", "slots" => ["track", "artist"], "category" => "music", "tone" => "hot"],
            ["key" => "music_sleeping_on", "template_en" => "Why are people sleeping on {artist}?", "template_sw" => "Kwa nini watu wanamkimbia {artist}?", "slots" => ["artist"], "category" => "music", "tone" => "hot"],
            ["key" => "music_lyrics_debate", "template_en" => "These lyrics from {artist} sparked a huge debate", "template_sw" => "Maneno haya kutoka {artist} yalichochea mjadala mkubwa", "slots" => ["artist"], "category" => "music", "tone" => "battle"],
            ["key" => "music_sample_caught", "template_en" => "Did {artist} sample {other_artist} without credit?", "template_sw" => "Je, {artist} alitumia wimbo wa {other_artist} bila ruhusa?", "slots" => ["artist", "other_artist"], "category" => "music", "tone" => "breaking"],
            ["key" => "music_album_season", "template_en" => "{artist} album season is here — are you ready?", "template_sw" => "Msimu wa albamu ya {artist} umefika — uko tayari?", "slots" => ["artist"], "category" => "music", "tone" => "hot"],
            ["key" => "music_diss_track", "template_en" => "New diss track targeting {artist} — who fired back?", "template_sw" => "Wimbo mpya wa kudiss {artist} — nani alijibu?", "slots" => ["artist"], "category" => "music", "tone" => "battle"],
            ["key" => "music_live_performance", "template_en" => "{artist} live performance has people talking", "template_sw" => "Onyesho la moja kwa moja la {artist} linawafanya watu wazungumze", "slots" => ["artist"], "category" => "music", "tone" => "hot"],
            ["key" => "music_best_era", "template_en" => "What is {artist} best era? The debate is heating up", "template_sw" => "Ipi ndiyo enzi bora ya {artist}? Mjadala unachomeka", "slots" => ["artist"], "category" => "music", "tone" => "battle"],

            // Sports
            ["key" => "sports_upset_alert", "template_en" => "Upset alert: {team} just shocked everyone", "template_sw" => "Tahadhari ya kushangaza: {team} wameshangaza kila mtu", "slots" => ["team"], "category" => "sports", "tone" => "breaking"],
            ["key" => "sports_transfer_rumor", "template_en" => "{player} transfer rumours are getting loud", "template_sw" => "Uvumi wa uhamisho wa {player} unazidi kusikika", "slots" => ["player"], "category" => "sports", "tone" => "hot"],
            ["key" => "sports_goat_debate", "template_en" => "GOAT debate: is {player} the greatest of all time?", "template_sw" => "Mjadala wa GOAT: je, {player} ni bora zaidi ya wakati wote?", "slots" => ["player"], "category" => "sports", "tone" => "battle"],
            ["key" => "sports_injury_crisis", "template_en" => "{team} injury crisis — who steps up?", "template_sw" => "Msiba wa majeruhi wa {team} — nani ataingia?", "slots" => ["team"], "category" => "sports", "tone" => "breaking"],
            ["key" => "sports_tactical_breakdown", "template_en" => "Tactical breakdown: how {team} won the impossible game", "template_sw" => "Uchambuzi wa mbinu: jinsi {team} walivyoshinda mchezo wa ajabu", "slots" => ["team"], "category" => "sports", "tone" => "hot"],
            ["key" => "sports_ref_controversy", "template_en" => "Referee controversy in the {event} — fans are furious", "template_sw" => "Utata wa refa katika {event} — mashabiki wamekasirika", "slots" => ["event"], "category" => "sports", "tone" => "hot"],
            ["key" => "sports_record_broken", "template_en" => "{player} breaks a {count}-year record", "template_sw" => "{player} anavunja rekodi ya miaka {count}", "slots" => ["player", "count"], "category" => "sports", "tone" => "breaking"],
            ["key" => "sports_league_table", "template_en" => "League table shake-up after {event}", "template_sw" => "Msukosuko wa jedwali la ligi baada ya {event}", "slots" => ["event"], "category" => "sports", "tone" => "breaking"],

            // Business
            ["key" => "biz_funding_round", "template_en" => "{company} just raised millions — what are they building?", "template_sw" => "{company} wamepata mamilioni — wanajenga nini?", "slots" => ["company"], "category" => "business", "tone" => "hot"],
            ["key" => "biz_layoffs", "template_en" => "{company} layoffs spark outrage online", "template_sw" => "Kufukuzwa kazi kwa {company} kunachochea hasira mtandaoni", "slots" => ["company"], "category" => "business", "tone" => "breaking"],
            ["key" => "biz_startup_story", "template_en" => "How {company} went from nothing to everything", "template_sw" => "Jinsi {company} walivyoenda kutoka sifuri hadi kila kitu", "slots" => ["company"], "category" => "business", "tone" => "hot"],
            ["key" => "biz_price_hike", "template_en" => "{topic} prices are up — here is who to blame", "template_sw" => "Bei za {topic} zimepanda — hapa ndio wa kulaumiwa", "slots" => ["topic"], "category" => "business", "tone" => "hot"],
            ["key" => "biz_entrepreneur_wins", "template_en" => "Young entrepreneur wins big with {topic}", "template_sw" => "Mjasiriamali mdogo ashinda sana na {topic}", "slots" => ["topic"], "category" => "business", "tone" => "hot"],
            ["key" => "biz_merger_shock", "template_en" => "{company} merger sends shockwaves through the industry", "template_sw" => "Muungano wa {company} unatuma mshtuko katika tasnia", "slots" => ["company"], "category" => "business", "tone" => "breaking"],
            ["key" => "biz_side_hustle", "template_en" => "Side hustle that makes more than a regular job: {topic}", "template_sw" => "Kazi ya ziada inayolipa zaidi ya kazi ya kawaida: {topic}", "slots" => ["topic"], "category" => "business", "tone" => "hot"],

            // Local
            ["key" => "local_project_launch", "template_en" => "New project in your area: {topic}", "template_sw" => "Mradi mpya katika eneo lako: {topic}", "slots" => ["topic"], "category" => "local", "tone" => "hot"],
            ["key" => "local_market_buzz", "template_en" => "Market day buzz: {topic} dominating Kariakoo today", "template_sw" => "Msisimko wa siku ya soko: {topic} inatawala Kariakoo leo", "slots" => ["topic"], "category" => "local", "tone" => "hot"],
            ["key" => "local_event_coming", "template_en" => "Big event coming to {location} — save the date", "template_sw" => "Tukio kubwa linakuja {location} — hifadhi tarehe", "slots" => ["location"], "category" => "local", "tone" => "hot"],
            ["key" => "local_infrastructure", "template_en" => "Infrastructure update in {location}: reactions are mixed", "template_sw" => "Maboresho ya miundombinu katika {location}: maoni yamegawanyika", "slots" => ["location"], "category" => "local", "tone" => "hot"],
            ["key" => "local_community_win", "template_en" => "Community win: {topic} finally happened in {location}", "template_sw" => "Ushindi wa jamii: {topic} hatimaye imetokea katika {location}", "slots" => ["topic", "location"], "category" => "local", "tone" => "hot"],
            ["key" => "local_viral_moment", "template_en" => "This {location} moment went viral for all the right reasons", "template_sw" => "Wakati huu wa {location} ulienea mtandaoni kwa sababu nzuri zote", "slots" => ["location"], "category" => "local", "tone" => "hot"],

            // Milestone
            ["key" => "milestone_first_100k", "template_en" => "{creator} just crossed 100K — celebrating a legend in the making", "template_sw" => "{creator} amepita 100K — tunasherehekea hadithi inayoundwa", "slots" => ["creator"], "category" => "milestone", "tone" => "milestone"],
            ["key" => "milestone_growth_rate", "template_en" => "{creator} grew {count} followers in one week — how?", "template_sw" => "{creator} alipata wafuatiliaji {count} kwa wiki moja — jinsi gani?", "slots" => ["creator", "count"], "category" => "milestone", "tone" => "hot"],
            ["key" => "milestone_underdog_rise", "template_en" => "From zero to {milestone}: {creator} story is incredible", "template_sw" => "Kutoka sifuri hadi {milestone}: Hadithi ya {creator} ni ya ajabu", "slots" => ["milestone", "creator"], "category" => "milestone", "tone" => "milestone"],

            // Viral / trending
            ["key" => "viral_challenge", "template_en" => "The {topic} challenge is taking over TZ", "template_sw" => "Changamoto ya {topic} inachukua Tanzania yote", "slots" => ["topic"], "category" => "general", "tone" => "hot"],
            ["key" => "viral_meme_origin", "template_en" => "The origin story of the {topic} meme you have seen everywhere", "template_sw" => "Hadithi ya asili ya meme ya {topic} uliyoona kila mahali", "slots" => ["topic"], "category" => "general", "tone" => "hot"],
            ["key" => "viral_reaction_roundup", "template_en" => "Best reactions to {topic} — ranked", "template_sw" => "Majibu bora ya {topic} — yaliyopangwa", "slots" => ["topic"], "category" => "general", "tone" => "hot"],
            ["key" => "viral_quote_moment", "template_en" => "Quote of the week: what {creator} said about {topic}", "template_sw" => "Nukuu ya wiki: alichosema {creator} kuhusu {topic}", "slots" => ["creator", "topic"], "category" => "general", "tone" => "hot"],
            ["key" => "viral_photo_moment", "template_en" => "This photo of {creator} has the internet going crazy", "template_sw" => "Picha hii ya {creator} imefanya mtandao uchanganyike", "slots" => ["creator"], "category" => "general", "tone" => "hot"],
            ["key" => "viral_thread_roundup", "template_en" => "Thread roundup: everything you missed about {topic}", "template_sw" => "Muhtasari wa mazungumzo: kila kilichokukimbia kuhusu {topic}", "slots" => ["topic"], "category" => "general", "tone" => "hot"],
            ["key" => "viral_prediction_right", "template_en" => "They predicted {topic} months ago — now everyone believes them", "template_sw" => "Walitabiri {topic} miezi iliyopita — sasa kila mtu anawaamini", "slots" => ["topic"], "category" => "general", "tone" => "hot"],
            ["key" => "viral_plot_twist", "template_en" => "Plot twist: {topic} was not what we thought", "template_sw" => "Mabadiliko ya ghafla: {topic} haikuwa tunachofikiria", "slots" => ["topic"], "category" => "general", "tone" => "breaking"],
        ];

        foreach ($templates as $t) {
            ThreadTitleTemplate::updateOrCreate(
                ["key" => $t["key"]],
                [
                    "template_en" => $t["template_en"],
                    "template_sw" => $t["template_sw"],
                    "slots" => $t["slots"],
                    "category" => $t["category"],
                    "tone" => $t["tone"],
                    "is_active" => true,
                ]
            );
        }

        $this->command->info("Inserted/updated " . count($templates) . " additional thread title templates.");
    }
}
