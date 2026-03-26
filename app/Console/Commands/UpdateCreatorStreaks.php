<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CreatorStreak;
use Illuminate\Support\Facades\DB;

class UpdateCreatorStreaks extends Command
{
    protected $signature = "flywheel:update-creator-streaks";
    protected $description = "Update creator posting streaks (48hr window, skip days, multiplier)";

    public function handle(): int
    {
        $now = now();
        $cutoff48h = $now->copy()->subHours(48);

        // All users who have ever posted
        $creatorIds = DB::table("posts")->distinct()->pluck("user_id");
        $updated = 0; $frozen = 0;

        foreach ($creatorIds as $userId) {
            $lastPost = DB::table("posts")->where("user_id", $userId)->max("created_at");
            $streak = CreatorStreak::firstOrCreate(["user_id" => $userId], [
                "current_streak_days" => 0, "longest_streak_days" => 0,
                "banked_skip_days" => 0, "is_frozen" => false, "streak_multiplier" => 1.0,
            ]);

            if ($lastPost && $lastPost >= $cutoff48h) {
                // Active — continue streak
                $streak->last_post_at = $lastPost;
                $days = $streak->is_frozen ? 1 : $streak->current_streak_days + 1;
                $streak->current_streak_days = $days;
                $streak->is_frozen = false;
                $streak->frozen_at = null;
                // Bank 1 skip day per 7 days of streak
                $streak->banked_skip_days = intdiv($days, 7);
                if ($days > $streak->longest_streak_days) {
                    $streak->longest_streak_days = $days;
                }
            } else {
                // Inactive — check banked skip days
                if ($streak->banked_skip_days > 0 && !$streak->is_frozen) {
                    $streak->banked_skip_days -= 1;
                } else {
                    $streak->is_frozen = true;
                    $streak->frozen_at = $now;
                    $frozen++;
                }
            }

            // Calculate multiplier
            $d = $streak->current_streak_days;
            $streak->streak_multiplier = match(true) {
                $d >= 90 => 1.5,
                $d >= 30 => 1.25,
                $d >= 7  => 1.1,
                default  => 1.0,
            };
            $streak->save();
            $updated++;
        }

        $this->info("Creator streaks: {$updated} processed, {$frozen} frozen.");
        return 0;
    }
}
