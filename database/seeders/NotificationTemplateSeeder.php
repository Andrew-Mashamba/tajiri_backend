<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\NotificationTemplate;

class NotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            // Digest
            ["key" => "digest_morning", "template_en" => "Good Morning! {count} threads trending in {city} today", "template_sw" => "Asubuhi Njema! Mada {count} vinavyovuma {city} leo", "slots" => ["count", "city"], "category" => "digest"],
            ["key" => "digest_evening", "template_en" => "Here's what happened today — the biggest thread got {reactions} reactions", "template_sw" => "Hizi ndio habari za leo — mada kubwa imepata majibu {reactions}", "slots" => ["reactions"], "category" => "digest"],
            ["key" => "digest_recap", "template_en" => "You missed {count} trending threads today", "template_sw" => "Umekosa mada {count} zinazovuma leo", "slots" => ["count"], "category" => "digest"],
            ["key" => "digest_personalized", "template_en" => "{count} threads on topics you follow", "template_sw" => "Mada {count} kuhusu mada unazofuata", "slots" => ["count"], "category" => "digest"],

            // FOMO
            ["key" => "fomo_thread_spike", "template_en" => "This thread has {count} new comments since you left", "template_sw" => "Mada hii ina maoni mapya {count} tangu ulipoondoka", "slots" => ["count"], "category" => "fomo"],
            ["key" => "fomo_viral_post", "template_en" => "A creator you follow just posted something going viral — {views} people watching", "template_sw" => "Muundaji unayemfuata amechapisha kitu kinachoenea — watu {views} wanatazama", "slots" => ["views"], "category" => "fomo"],
            ["key" => "fomo_trending_topic", "template_en" => "{topic} is trending right now", "template_sw" => "{topic} inavuma sasa hivi", "slots" => ["topic"], "category" => "fomo"],
            ["key" => "fomo_missed", "template_en" => "You're missing out — {count} posts since your last visit", "template_sw" => "Unakosa — machapisho {count} tangu ulipotembelea mwisho", "slots" => ["count"], "category" => "fomo"],

            // Streak
            ["key" => "streak_warning", "template_en" => "You have {hours} hours left on your {days}-day streak — quick post to keep it alive!", "template_sw" => "Una masaa {hours} yaliyobaki kwa mfululizo wako wa siku {days} — chapisha haraka!", "slots" => ["hours", "days"], "category" => "streak"],
            ["key" => "streak_frozen", "template_en" => "Your {days}-day streak has been frozen. Post to resume!", "template_sw" => "Mfululizo wako wa siku {days} umegandishwa. Chapisha ili kuendelea!", "slots" => ["days"], "category" => "streak"],
            ["key" => "streak_resumed", "template_en" => "Welcome back! Your {days}-day streak is alive again", "template_sw" => "Karibu tena! Mfululizo wako wa siku {days} umerejesha", "slots" => ["days"], "category" => "streak"],
            ["key" => "streak_milestone", "template_en" => "Amazing! {days}-day posting streak! Keep going!", "template_sw" => "Vizuri sana! Mfululizo wa siku {days} wa kuchapisha! Endelea!", "slots" => ["days"], "category" => "streak"],

            // Milestone
            ["key" => "milestone_followers", "template_en" => "Congratulations! You just hit {count} followers!", "template_sw" => "Hongera! Umefika wafuasi {count}!", "slots" => ["count"], "category" => "milestone"],
            ["key" => "milestone_views", "template_en" => "Your post just passed {count} views!", "template_sw" => "Chapisho lako limevuka watazamaji {count}!", "slots" => ["count"], "category" => "milestone"],
            ["key" => "milestone_first_thread", "template_en" => "Your post triggered a gossip thread for the first time!", "template_sw" => "Chapisho lako limeanzisha mada kwa mara ya kwanza!", "slots" => [], "category" => "milestone"],
            ["key" => "milestone_earnings", "template_en" => "You've earned TSh {amount} this month!", "template_sw" => "Umepata TSh {amount} mwezi huu!", "slots" => ["amount"], "category" => "milestone"],

            // Report
            ["key" => "report_weekly", "template_en" => "Your Week on TAJIRI: TSh {earnings} earned, {views} views", "template_sw" => "Wiki Yako TAJIRI: TSh {earnings} umepata, watazamaji {views}", "slots" => ["earnings", "views"], "category" => "report"],
            ["key" => "report_improvement", "template_en" => "Your engagement is up {percent}% this week!", "template_sw" => "Ushiriki wako umeongezeka {percent}% wiki hii!", "slots" => ["percent"], "category" => "report"],
            ["key" => "report_top_creator", "template_en" => "You're in the top {percent}% of creators this week", "template_sw" => "Uko katika {percent}% ya juu ya waundaji wiki hii", "slots" => ["percent"], "category" => "report"],
        ];

        foreach ($templates as $t) {
            NotificationTemplate::updateOrCreate(
                ["key" => $t["key"]],
                [
                    "template_en" => $t["template_en"],
                    "template_sw" => $t["template_sw"],
                    "slots" => $t["slots"],
                    "category" => $t["category"],
                ]
            );
        }
    }
}
