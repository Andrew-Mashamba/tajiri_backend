<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ViewerStreak;
use Illuminate\Support\Facades\DB;

class UpdateViewerStreaks extends Command
{
    protected $signature = "flywheel:update-viewer-streaks";
    protected $description = "Update viewer daily open streaks based on user_events";

    public function handle(): int
    {
        $today = now()->toDateString();
        // Users active today (have events)
        $activeUserIds = DB::table("user_events")
            ->whereDate("timestamp", $today)
            ->distinct()
            ->pluck("user_id");

        $updated = 0;
        $frozen = 0;

        foreach ($activeUserIds as $userId) {
            $streak = ViewerStreak::firstOrCreate(["user_id" => $userId], [
                "current_streak_days" => 0, "longest_streak_days" => 0,
                "is_frozen" => false,
            ]);
            // Skip if already updated today (idempotency)
            if ($streak->last_active_date === $today) {
                continue;
            }
            $streak->current_streak_days += 1;
            $streak->is_frozen = false;
            $streak->frozen_at = null;
            $streak->last_active_date = $today;
            if ($streak->current_streak_days > $streak->longest_streak_days) {
                $streak->longest_streak_days = $streak->current_streak_days;
            }
            $streak->save();
            $updated++;
        }

        // Freeze inactive users
        ViewerStreak::where("is_frozen", false)
            ->where(function ($q) use ($today) {
                $q->whereNull("last_active_date")->orWhere("last_active_date", "<", $today);
            })
            ->whereNotIn("user_id", $activeUserIds)
            ->update(["is_frozen" => true, "frozen_at" => now()]);

        $frozen = ViewerStreak::where("is_frozen", true)->whereDate("frozen_at", $today)->count();
        $this->info("Viewer streaks: {$updated} updated, {$frozen} frozen.");
        return 0;
    }
}
