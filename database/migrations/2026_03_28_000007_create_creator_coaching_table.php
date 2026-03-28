<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_coaching', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('creator_id');
            $table->jsonb('advice');
            $table->date('week_start');
            $table->timestamp('generated_at')->useCurrent();

            $table->unique(['creator_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_coaching');
    }
};
