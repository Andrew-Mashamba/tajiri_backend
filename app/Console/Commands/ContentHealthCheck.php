<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

class ContentHealthCheck extends Command
{
    protected $signature = 'content:health-check';
    protected $description = 'Check all Content Engine subsystems';

    public function handle(): int
    {
        $this->info('Content Engine Health Check');
        $this->info(str_repeat('=', 50));

        $allOk = true;

        // PostgreSQL
        $allOk &= $this->check('PostgreSQL', function () {
            $count = DB::table('content_documents')->count();
            return "OK ({$count} documents)";
        });

        // pgvector
        $allOk &= $this->check('pgvector', function () {
            DB::select("SELECT '[1,2,3]'::vector");
            return 'OK (extension loaded)';
        });

        // Typesense
        $allOk &= $this->check('Typesense', function () {
            $config = config('content-engine.typesense');
            $url = "{$config['protocol']}://{$config['host']}:{$config['port']}/health";
            $response = Http::withHeaders(['X-TYPESENSE-API-KEY' => $config['api_key']])->timeout(5)->get($url);
            if (!$response->successful()) throw new \Exception('Not responding');
            return 'OK';
        });

        // Typesense Collection
        $allOk &= $this->check('Typesense Collection', function () {
            $config = config('content-engine.typesense');
            $url = "{$config['protocol']}://{$config['host']}:{$config['port']}/collections/{$config['collection']}";
            $response = Http::withHeaders(['X-TYPESENSE-API-KEY' => $config['api_key']])->timeout(5)->get($url);
            if (!$response->successful()) throw new \Exception('Collection not found');
            $data = $response->json();
            return "OK ({$data['num_documents']} docs)";
        });

        // Redis
        $allOk &= $this->check('Redis', function () {
            $pong = Redis::ping();
            return 'OK (PONG)';
        });

        // Embedding Service
        $allOk &= $this->check('Embedding Service', function () {
            $url = config('content-engine.embedding.url') . '/health';
            $response = Http::timeout(5)->get($url);
            if (!$response->successful()) throw new \Exception('Not responding');
            $data = $response->json();
            return "OK ({$data['model']}, {$data['dimensions']}d)";
        });

        // Claude CLI
        $allOk &= $this->check('Claude CLI', function () {
            $cliPath = config('content-engine.claude.cli_path', 'claude');
            $output = shell_exec("{$cliPath} --version 2>&1");
            if (empty($output)) throw new \Exception('Not found');
            return 'OK (' . trim($output) . ')';
        });

        // Scoring Config
        $allOk &= $this->check('Scoring Config', function () {
            $count = DB::table('scoring_config')->count();
            if ($count < 6) throw new \Exception("Only {$count} weights (expected 6)");
            return "OK ({$count} weights)";
        });

        // Feature Flags
        $allOk &= $this->check('Feature Flags', function () {
            $count = DB::table('feature_flags')->count();
            return "OK ({$count} flags)";
        });

        $this->newLine();
        if ($allOk) {
            $this->info('All subsystems operational.');
        } else {
            $this->error('Some subsystems failed. Fix issues above before proceeding.');
        }

        return $allOk ? 0 : 1;
    }

    private function check(string $name, callable $fn): bool
    {
        try {
            $result = $fn();
            $this->line("  ✓ {$name}: {$result}");
            return true;
        } catch (\Throwable $e) {
            $this->error("  ✗ {$name}: FAILED — {$e->getMessage()}");
            return false;
        }
    }
}
