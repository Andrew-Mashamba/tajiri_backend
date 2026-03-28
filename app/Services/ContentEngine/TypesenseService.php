<?php

namespace App\Services\ContentEngine;

use App\Models\ContentDocument;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TypesenseService
{
    public static function upsert(ContentDocument $doc): bool
    {
        $config = config('content-engine.typesense');
        $url = "{$config['protocol']}://{$config['host']}:{$config['port']}/collections/{$config['collection']}/documents?action=upsert";

        $payload = [
            'id' => (string) $doc->id,
            'source_type' => $doc->source_type,
            'source_id' => (int) $doc->source_id,
            'title' => $doc->title ?? '',
            'body' => $doc->body ?? '',
            'hashtags' => $doc->hashtags ?? [],
            'mentions' => $doc->mentions ?? [],
            'language' => $doc->language ?? '',
            'creator_id' => (int) $doc->creator_id,
            'creator_tier' => $doc->creator_tier ?? '',
            'category' => $doc->category ?? '',
            'content_tier' => $doc->content_tier ?? 'medium',
            'media_types' => $doc->media_types ?? [],
            'region_name' => $doc->region_name ?? '',
            'district_name' => $doc->district_name ?? '',
            'privacy' => $doc->privacy ?? 'public',
            'composite_score' => (float) ($doc->composite_score ?? 0),
            'engagement_score' => (float) ($doc->engagement_score ?? 0),
            'freshness_score' => (float) ($doc->freshness_score ?? 0),
            'trending_score' => (float) ($doc->trending_score ?? 0),
            'quality_score' => (float) ($doc->quality_score ?? 0),
            'content_rank' => (float) ($doc->content_rank ?? 0),
            'creator_authority' => (float) ($doc->creator_authority ?? 0),
            'published_at' => $doc->published_at ? $doc->published_at->timestamp : 0,
            'indexed_at' => now()->timestamp,
        ];

        try {
            $response = Http::withHeaders([
                'X-TYPESENSE-API-KEY' => $config['api_key'],
                'Content-Type' => 'application/json',
            ])->timeout(10)->post($url, $payload);

            if (!$response->successful()) {
                Log::error('TypesenseService::upsert failed', [
                    'doc_id' => $doc->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            Log::error('TypesenseService::upsert exception', [
                'doc_id' => $doc->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public static function delete(int $docId): bool
    {
        $config = config('content-engine.typesense');
        $url = "{$config['protocol']}://{$config['host']}:{$config['port']}/collections/{$config['collection']}/documents/{$docId}";

        try {
            $response = Http::withHeaders([
                'X-TYPESENSE-API-KEY' => $config['api_key'],
            ])->timeout(10)->delete($url);
            return $response->successful() || $response->status() === 404;
        } catch (\Throwable $e) {
            Log::error('TypesenseService::delete exception', ['doc_id' => $docId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public static function batchUpsert(array $documents): int
    {
        $config = config('content-engine.typesense');
        $url = "{$config['protocol']}://{$config['host']}:{$config['port']}/collections/{$config['collection']}/documents/import?action=upsert";

        $jsonl = '';
        foreach ($documents as $doc) {
            $payload = [
                'id' => (string) $doc->id,
                'source_type' => $doc->source_type,
                'source_id' => (int) $doc->source_id,
                'title' => $doc->title ?? '',
                'body' => $doc->body ?? '',
                'hashtags' => $doc->hashtags ?? [],
                'mentions' => $doc->mentions ?? [],
                'language' => $doc->language ?? '',
                'creator_id' => (int) $doc->creator_id,
                'creator_tier' => $doc->creator_tier ?? '',
                'category' => $doc->category ?? '',
                'content_tier' => $doc->content_tier ?? 'medium',
                'media_types' => $doc->media_types ?? [],
                'region_name' => $doc->region_name ?? '',
                'district_name' => $doc->district_name ?? '',
                'privacy' => $doc->privacy ?? 'public',
                'composite_score' => (float) ($doc->composite_score ?? 0),
                'engagement_score' => (float) ($doc->engagement_score ?? 0),
                'freshness_score' => (float) ($doc->freshness_score ?? 0),
                'trending_score' => (float) ($doc->trending_score ?? 0),
                'quality_score' => (float) ($doc->quality_score ?? 0),
                'content_rank' => (float) ($doc->content_rank ?? 0),
                'creator_authority' => (float) ($doc->creator_authority ?? 0),
                'published_at' => $doc->published_at ? $doc->published_at->timestamp : 0,
                'indexed_at' => now()->timestamp,
            ];
            $jsonl .= json_encode($payload) . "\n";
        }

        try {
            $response = Http::withHeaders([
                'X-TYPESENSE-API-KEY' => $config['api_key'],
                'Content-Type' => 'text/plain',
            ])->timeout(30)->withBody($jsonl, 'text/plain')->post($url);

            if (!$response->successful()) {
                Log::error('TypesenseService::batchUpsert failed', ['status' => $response->status()]);
                return 0;
            }

            $lines = explode("\n", trim($response->body()));
            $success = 0;
            foreach ($lines as $line) {
                $result = json_decode($line, true);
                if (isset($result['success']) && $result['success']) {
                    $success++;
                }
            }
            return $success;
        } catch (\Throwable $e) {
            Log::error('TypesenseService::batchUpsert exception', ['error' => $e->getMessage()]);
            return 0;
        }
    }
}
