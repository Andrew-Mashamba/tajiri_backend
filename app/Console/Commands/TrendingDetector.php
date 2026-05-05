<?php

namespace App\Console\Commands;

use App\Models\ContentDocument;
use App\Services\ContentEngine\SignalService;
use App\Services\ContentEngine\TrendingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class TrendingDetector extends Command
{
    protected $signature = 'signal:detect-trending';
    protected $description = 'Check velocity for recently active documents and update trending sorted sets';

    public function handle(): int
    {
        // Use dedicated trending:candidates set (populated by SignalService)
        $candidateKey = 'trending:candidates';
        $dirtyMembers = Redis::sMembers($candidateKey) ?: [];
        if (!empty($dirtyMembers)) {
            Redis::del($candidateKey);
        }

        if (empty($dirtyMembers)) {
            return 0;
        }

        $updated = 0;
        $rising = 0;
        $breaking = 0;

        foreach ($dirtyMembers as $member) {
            [$sourceType, $sourceId] = explode(':', $member, 2);
            $sourceId = (int) $sourceId;

            $trendingScore = TrendingService::computeTrendingScore($sourceType, $sourceId);

            // Anti-gaming Rule 2: Cap trending_score if fraud flagged
            $docKey = SignalService::docKey($sourceType, $sourceId);
            if (Redis::hGet($docKey, 'fraud_flagged') === '1') {
                $cap = config('content-engine.anti_gaming.fraud_trending_cap', 50);
                $trendingScore = min($trendingScore, $cap);
            }

            Redis::hSet($docKey, 'trending_score', $trendingScore);

            $doc = ContentDocument::where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->first(['region_name', 'category', 'hashtags']);

            if ($doc) {
                TrendingService::updateTrendingSets(
                    $sourceType,
                    $sourceId,
                    $trendingScore,
                    $doc->region_name,
                    $doc->category,
                    $doc->hashtags ?? []
                );
            }

            // Check velocity classification
            $fiveMinKey = "signals_5min:{$sourceType}:{$sourceId}";
            $current5min = (int) (Redis::hGet($fiveMinKey, 'count') ?? 0);
            $avg5min24h = (float) (Redis::hGet($docKey, 'avg_5min_24h') ?? 1);
            $velocity = $current5min / max($avg5min24h, 1);

            $state = TrendingService::classifyTrending($velocity);
            if ($state === 'rising') $rising++;
            if ($state === 'breaking') {
                $breaking++;
                Log::info("BREAKING content detected", [
                    'source' => "{$sourceType}:{$sourceId}",
                    'velocity' => round($velocity, 1),
                    'trending_score' => $trendingScore,
                ]);
            }

            // Update rolling 24h average (exponential moving average)
            $newAvg = ($avg5min24h * 0.95) + ($current5min * 0.05);
            Redis::hSet($docKey, 'avg_5min_24h', round($newAvg, 4));

            $updated++;
        }

        $pruned = TrendingService::pruneGlobalTrending(0.5);

        $this->info("Trending: {$updated} checked, {$rising} rising, {$breaking} breaking, {$pruned} pruned");

        return 0;
    }
}
