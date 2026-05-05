<?php

namespace App\Console\Commands;

use App\Models\CollaborationSuggestion;
use App\Models\CreatorScore;
use Illuminate\Console\Command;

class DetectCollaborations extends Command
{
    protected $signature = 'flywheel:detect-collaborations';
    protected $description = 'Generate collaboration suggestions between creators with complementary audiences';

    public function handle(): int
    {
        $creators = CreatorScore::where('score', '>', 0)
            ->orderByDesc('score')
            ->limit(100)
            ->get();

        $created = 0;

        foreach ($creators as $i => $a) {
            foreach ($creators as $j => $b) {
                if ($i >= $j) continue;

                $tierA = $a->tier ?? '';
                $tierB = $b->tier ?? '';

                if ($tierA !== $tierB && $tierA && $tierB) {
                    $score = min(100, ($a->score + $b->score) / 2);

                    $exists = CollaborationSuggestion::where('user_id', $a->user_id)
                        ->where('suggested_user_id', $b->user_id)
                        ->exists();

                    if (!$exists) {
                        CollaborationSuggestion::create([
                            'user_id' => $a->user_id,
                            'suggested_user_id' => $b->user_id,
                            'reason' => 'Complementary audiences: ' . $tierA . ' + ' . $tierB,
                            'compatibility_score' => $score,
                            'status' => 'pending',
                        ]);

                        CollaborationSuggestion::firstOrCreate(
                            ['user_id' => $b->user_id, 'suggested_user_id' => $a->user_id],
                            [
                                'reason' => 'Complementary audiences: ' . $tierB . ' + ' . $tierA,
                                'compatibility_score' => $score,
                                'status' => 'pending',
                            ]
                        );


                        // Notify both users about the new collaboration suggestion
                        \App\Services\FcmNotificationService::sendToUser($a->user_id, 'collaboration_suggestion', [], 'New Collaboration', 'You have a new collaboration suggestion!');
                        \App\Services\FcmNotificationService::sendToUser($b->user_id, 'collaboration_suggestion', [], 'New Collaboration', 'You have a new collaboration suggestion!');
                        $created += 2;
                    }
                }
            }
        }

        $this->info("Created {$created} collaboration suggestions.");
        return self::SUCCESS;
    }
}
