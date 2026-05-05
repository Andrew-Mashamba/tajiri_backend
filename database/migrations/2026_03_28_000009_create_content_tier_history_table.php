<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_tier_history', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('document_id');
            $table->string('old_tier', 20)->nullable();
            $table->string('new_tier', 20);
            $table->float('composite_score');
            $table->timestamp('changed_at')->useCurrent();

            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_tier_history');
    }
};
