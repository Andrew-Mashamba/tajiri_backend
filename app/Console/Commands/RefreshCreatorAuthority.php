<?php

namespace App\Console\Commands;

use App\Models\ContentDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RefreshCreatorAuthority extends Command
{
    protected $signature = 'content:refresh-creator-authority';
    protected $description = 'Sync creator_authority from creator_scores table into content_documents';

    public function handle(): int
    {
        $hasCreatorScores = DB::getSchemaBuilder()->hasTable('creator_scores');
        if (!$hasCreatorScores) {
            $this->warn('creator_scores table not found — skipping');
            return 0;
        }

        $maxScore = DB::table('creator_scores')->max('score') ?: 1;

        $affected = DB::update("
            UPDATE content_documents cd
            SET creator_authority = ROUND((cs.score / ? * 100)::numeric, 2),
                scores_updated_at = NOW()
            FROM creator_scores cs
            WHERE cd.creator_id = cs.user_id
              AND cd.creator_authority != ROUND((cs.score / ? * 100)::numeric, 2)
        ", [$maxScore, $maxScore]);

        $hasCreatorTiers = DB::getSchemaBuilder()->hasColumn('user_profiles', 'creator_tier');
        if ($hasCreatorTiers) {
            $tierAffected = DB::update("
                UPDATE content_documents cd
                SET creator_tier = up.creator_tier
                FROM user_profiles up
                WHERE cd.creator_id = up.id
                  AND (cd.creator_tier IS NULL OR cd.creator_tier != up.creator_tier)
            ");
            $this->info("Creator tiers: {$tierAffected} updated");
        }

        $this->info("Creator authority: {$affected} documents updated (max score: {$maxScore})");
        return 0;
    }
}
