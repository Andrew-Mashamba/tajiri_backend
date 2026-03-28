<?php

namespace App\Jobs\ContentEngine;

use App\Models\ContentDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ClaudeScoreContentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;
    public int $backoff = 60;

    public function __construct(public int $documentId)
    {
        $this->onQueue('content-scoring');
    }

    public function handle(): void
    {
        $doc = ContentDocument::find($this->documentId);

        if (!$doc) {
            return;
        }

        $text = trim(($doc->title ?? '') . ' ' . ($doc->body ?? ''));

        if (empty($text)) {
            $doc->update(['quality_score' => 5.0, 'spam_score' => 0]);
            self::recomputeComposite($doc);
            return;
        }

        if (mb_strlen($text) > 1000) {
            $text = mb_substr($text, 0, 1000);
        }

        $cliPath = config('content-engine.claude.cli_path', 'claude');
        $model = config('content-engine.claude.scoring_model', 'haiku');

        $prompt = "You are a content quality scorer for a Tanzanian social media platform.\nEvaluate this content and respond with ONLY a JSON object (no other text):\n\nContent type: {$doc->source_type}\nText: {$text}\nHas media: " . (empty($doc->media_types) ? 'no' : implode(', ', $doc->media_types)) . "\n\nRespond with exactly this JSON format:\n{\"quality_score\": <float 0-10>, \"spam_score\": <float 0-10>, \"category\": \"<string>\"}\n\nquality_score: 0=garbage, 5=average, 10=exceptional.\nspam_score: 0=legitimate, 10=definite spam.\ncategory: One of: entertainment, music, sports, news, business, education, lifestyle, technology, politics, religion, food, travel, fashion, health, comedy, other";

        try {
            $escapedPrompt = escapeshellarg($prompt);
            $output = shell_exec("{$cliPath} -p {$escapedPrompt} --model {$model} --output-format text 2>/dev/null");

            if (empty($output)) {
                Log::warning("ClaudeScoreContentJob: empty Claude response", ['id' => $this->documentId]);
                $doc->update(['quality_score' => 5.0, 'spam_score' => 0]);
                self::recomputeComposite($doc);
                return;
            }

            preg_match('/\{[^}]+\}/', $output, $matches);

            if (empty($matches[0])) {
                Log::warning("ClaudeScoreContentJob: no JSON in response", ['id' => $this->documentId]);
                $doc->update(['quality_score' => 5.0, 'spam_score' => 0]);
                self::recomputeComposite($doc);
                return;
            }

            $scores = json_decode($matches[0], true);

            $qualityScore = max(0, min(10, (float) ($scores['quality_score'] ?? 5)));
            $spamScore = max(0, min(10, (float) ($scores['spam_score'] ?? 0)));
            $category = $scores['category'] ?? $doc->category;

            $updates = [
                'quality_score' => $qualityScore,
                'spam_score' => $spamScore,
            ];

            if ($category && empty($doc->category)) {
                $updates['category'] = $category;
            }

            if ($spamScore > 7) {
                $updates['content_tier'] = ContentDocument::TIER_BLACKHOLE;
            }

            $doc->update($updates);
            self::recomputeComposite($doc);

        } catch (\Throwable $e) {
            Log::error("ClaudeScoreContentJob: failed", [
                'id' => $this->documentId,
                'error' => $e->getMessage(),
            ]);
            $doc->update(['quality_score' => 5.0, 'spam_score' => 0]);
            self::recomputeComposite($doc);
        }
    }

    public static function recomputeComposite(ContentDocument $doc): void
    {
        $doc->recomputeCompositeAndTier(save: true);
    }
}
