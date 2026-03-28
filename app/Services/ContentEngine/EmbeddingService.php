<?php

namespace App\Services\ContentEngine;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmbeddingService
{
    public static function embed(string $text): ?array
    {
        $config = config('content-engine.embedding');

        try {
            $response = Http::timeout($config['timeout'])
                ->post($config['url'] . '/embed', ['text' => $text]);

            if (!$response->successful()) {
                Log::error('EmbeddingService::embed failed', ['status' => $response->status()]);
                return null;
            }

            return $response->json()['embedding'] ?? null;
        } catch (\Throwable $e) {
            Log::error('EmbeddingService::embed exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public static function embedBatch(array $texts): array
    {
        $config = config('content-engine.embedding');
        $batchSize = $config['batch_size'] ?? 50;
        $chunks = array_chunk($texts, $batchSize);
        $allEmbeddings = [];

        foreach ($chunks as $chunk) {
            try {
                $response = Http::timeout(60)
                    ->post($config['url'] . '/embed/batch', ['texts' => $chunk]);

                if ($response->successful()) {
                    $allEmbeddings = array_merge($allEmbeddings, $response->json()['embeddings'] ?? []);
                } else {
                    Log::error('EmbeddingService::embedBatch failed', ['status' => $response->status()]);
                    $allEmbeddings = array_merge($allEmbeddings, array_fill(0, count($chunk), null));
                }
            } catch (\Throwable $e) {
                Log::error('EmbeddingService::embedBatch exception', ['error' => $e->getMessage()]);
                $allEmbeddings = array_merge($allEmbeddings, array_fill(0, count($chunk), null));
            }
        }

        return $allEmbeddings;
    }
}
