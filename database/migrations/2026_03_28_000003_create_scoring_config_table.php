<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scoring_config', function (Blueprint $table) {
            $table->string('key', 50)->primary();
            $table->float('value');
            $table->text('description')->nullable();
            $table->timestamp('updated_at')->useCurrent();
        });

        // Seed default weights
        DB::table('scoring_config')->insert([
            ['key' => 'w_freshness', 'value' => 0.25, 'description' => 'Weight for time-decay freshness'],
            ['key' => 'w_engagement', 'value' => 0.30, 'description' => 'Weight for real-time engagement signals'],
            ['key' => 'w_quality', 'value' => 0.15, 'description' => 'Weight for AI quality assessment'],
            ['key' => 'w_content_rank', 'value' => 0.15, 'description' => 'Weight for graph authority'],
            ['key' => 'w_creator_auth', 'value' => 0.10, 'description' => 'Weight for creator influence'],
            ['key' => 'w_trending', 'value' => 0.05, 'description' => 'Weight for trending spike bonus'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('scoring_config');
    }
};
