<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ThreadTitleTemplate;

class ThreadTitleTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            // Hot tone
            ["key" => "trending_hot", "template_en" => "{category} Is On Fire", "template_sw" => "{category} Imewaka", "slots" => ["category"], "category" => "general", "tone" => "hot"],
            ["key" => "trending_viral", "template_en" => "{count}+ People Talking About This", "template_sw" => "Watu {count}+ Wanazungumzia Hii", "slots" => ["count"], "category" => "general", "tone" => "hot"],
            ["key" => "trending_general", "template_en" => "Everyone Is Talking About {topic}", "template_sw" => "Kila Mtu Anazungumzia {topic}", "slots" => ["topic"], "category" => "general", "tone" => "hot"],
            ["key" => "trending_reactions", "template_en" => "{topic} Has People Reacting", "template_sw" => "{topic} Imeibua Hisia", "slots" => ["topic"], "category" => "general", "tone" => "hot"],
            ["key" => "trending_debate", "template_en" => "The Great {topic} Debate", "template_sw" => "Mjadala Mkubwa wa {topic}", "slots" => ["topic"], "category" => "general", "tone" => "hot"],
            ["key" => "trending_buzzing", "template_en" => "{topic} Is Buzzing Right Now", "template_sw" => "{topic} Inaendelea Sasa", "slots" => ["topic"], "category" => "general", "tone" => "hot"],
            ["key" => "trending_cant_stop", "template_en" => "People Can't Stop Sharing {topic}", "template_sw" => "Watu Hawaacha Kushiriki {topic}", "slots" => ["topic"], "category" => "general", "tone" => "hot"],

            // Breaking tone
            ["key" => "trending_breaking", "template_en" => "Breaking: {topic}", "template_sw" => "Mpya: {topic}", "slots" => ["topic"], "category" => "general", "tone" => "breaking"],
            ["key" => "trending_developing", "template_en" => "Developing Story: {topic}", "template_sw" => "Habari Inayoendelea: {topic}", "slots" => ["topic"], "category" => "general", "tone" => "breaking"],
            ["key" => "trending_just_in", "template_en" => "Just In: {topic}", "template_sw" => "Sasa Hivi: {topic}", "slots" => ["topic"], "category" => "general", "tone" => "breaking"],
            ["key" => "trending_alert", "template_en" => "Alert: {topic}", "template_sw" => "Tahadhari: {topic}", "slots" => ["topic"], "category" => "general", "tone" => "breaking"],

            // Battle tone
            ["key" => "trending_battle", "template_en" => "{creator_a} vs {creator_b}", "template_sw" => "{creator_a} dhidi ya {creator_b}", "slots" => ["creator_a", "creator_b"], "category" => "general", "tone" => "battle"],
            ["key" => "trending_sides", "template_en" => "Pick a Side: {topic}", "template_sw" => "Chagua Upande: {topic}", "slots" => ["topic"], "category" => "general", "tone" => "battle"],
            ["key" => "trending_clash", "template_en" => "The {topic} Clash", "template_sw" => "Mgongano wa {topic}", "slots" => ["topic"], "category" => "general", "tone" => "battle"],

            // Milestone tone
            ["key" => "trending_milestone", "template_en" => "{creator} Just Hit {milestone}", "template_sw" => "{creator} Amefika {milestone}", "slots" => ["creator", "milestone"], "category" => "general", "tone" => "milestone"],
            ["key" => "trending_celebrate", "template_en" => "Celebrate: {creator} Reaches {milestone}", "template_sw" => "Sherehe: {creator} Amefika {milestone}", "slots" => ["creator", "milestone"], "category" => "general", "tone" => "milestone"],
            ["key" => "trending_record", "template_en" => "{creator} Breaks a Record!", "template_sw" => "{creator} Amevunja Rekodi!", "slots" => ["creator"], "category" => "general", "tone" => "milestone"],

            // Local tone
            ["key" => "trending_local", "template_en" => "Happening Near You: {topic}", "template_sw" => "Kinachoendelea Karibu Nawe: {topic}", "slots" => ["topic"], "category" => "local", "tone" => "local"],
            ["key" => "trending_neighborhood", "template_en" => "Your Area Is Talking About {topic}", "template_sw" => "Eneo Lako Linazungumzia {topic}", "slots" => ["topic"], "category" => "local", "tone" => "local"],
            ["key" => "trending_local_buzz", "template_en" => "Local Buzz: {topic}", "template_sw" => "Habari za Mtaani: {topic}", "slots" => ["topic"], "category" => "local", "tone" => "local"],
            ["key" => "trending_community", "template_en" => "Community Alert: {topic}", "template_sw" => "Taarifa ya Jamii: {topic}", "slots" => ["topic"], "category" => "local", "tone" => "local"],

            // Entertainment
            ["key" => "trending_entertainment_hot", "template_en" => "Entertainment Is On Fire: {topic}", "template_sw" => "Burudani Imewaka: {topic}", "slots" => ["topic"], "category" => "entertainment", "tone" => "hot"],
            ["key" => "trending_celeb", "template_en" => "{celebrity} Is Trending", "template_sw" => "{celebrity} Anavuma", "slots" => ["celebrity"], "category" => "entertainment", "tone" => "hot"],
            ["key" => "trending_drama", "template_en" => "Drama: {topic}", "template_sw" => "Mchezo: {topic}", "slots" => ["topic"], "category" => "entertainment", "tone" => "hot"],
            ["key" => "trending_showbiz", "template_en" => "Showbiz Buzz: {topic}", "template_sw" => "Habari za Wasanii: {topic}", "slots" => ["topic"], "category" => "entertainment", "tone" => "hot"],

            // Music
            ["key" => "trending_music", "template_en" => "{track} Is Taking Over", "template_sw" => "{track} Inashika", "slots" => ["track"], "category" => "music", "tone" => "hot"],
            ["key" => "trending_bongo", "template_en" => "Bongo Flava Alert: {topic}", "template_sw" => "Bongo Flava: {topic}", "slots" => ["topic"], "category" => "music", "tone" => "hot"],
            ["key" => "trending_new_release", "template_en" => "New Release: {track} by {artist}", "template_sw" => "Wimbo Mpya: {track} na {artist}", "slots" => ["track", "artist"], "category" => "music", "tone" => "breaking"],
            ["key" => "trending_chart", "template_en" => "{track} Climbs the Charts", "template_sw" => "{track} Inapanda Chati", "slots" => ["track"], "category" => "music", "tone" => "hot"],

            // Sports
            ["key" => "trending_sports", "template_en" => "Game Day: {event}", "template_sw" => "Siku ya Mchezo: {event}", "slots" => ["event"], "category" => "sports", "tone" => "hot"],
            ["key" => "trending_match", "template_en" => "{team_a} vs {team_b} — Who Wins?", "template_sw" => "{team_a} dhidi ya {team_b} — Nani Atashinda?", "slots" => ["team_a", "team_b"], "category" => "sports", "tone" => "battle"],
            ["key" => "trending_sports_result", "template_en" => "Result: {event}", "template_sw" => "Matokeo: {event}", "slots" => ["event"], "category" => "sports", "tone" => "breaking"],
            ["key" => "trending_goal", "template_en" => "GOAL! {player} Scores for {team}", "template_sw" => "BAAOO! {player} Amefunga kwa {team}", "slots" => ["player", "team"], "category" => "sports", "tone" => "breaking"],

            // Business
            ["key" => "trending_business", "template_en" => "Market Buzz: {topic}", "template_sw" => "Habari za Soko: {topic}", "slots" => ["topic"], "category" => "business", "tone" => "hot"],
            ["key" => "trending_biashara", "template_en" => "Business Alert: {topic}", "template_sw" => "Taarifa ya Biashara: {topic}", "slots" => ["topic"], "category" => "business", "tone" => "breaking"],
            ["key" => "trending_hustle", "template_en" => "Hustle Spotlight: {topic}", "template_sw" => "Biashara Ndogo: {topic}", "slots" => ["topic"], "category" => "business", "tone" => "hot"],
            ["key" => "trending_deals", "template_en" => "Deals: {topic}", "template_sw" => "Ofa: {topic}", "slots" => ["topic"], "category" => "business", "tone" => "hot"],
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
                ]
            );
        }
    }
}
