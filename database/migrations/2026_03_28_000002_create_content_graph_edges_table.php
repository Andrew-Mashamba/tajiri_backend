<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_graph_edges', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 20);
            $table->bigInteger('source_id');
            $table->string('target_type', 20);
            $table->bigInteger('target_id');
            $table->string('edge_type', 30);
            $table->float('weight')->default(1.0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['source_type', 'source_id', 'target_type', 'target_id', 'edge_type'], 'cge_unique');

            $table->index(['source_type', 'source_id'], 'idx_cge_source');
            $table->index(['target_type', 'target_id'], 'idx_cge_target');
            $table->index('edge_type', 'idx_cge_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_graph_edges');
    }
};
