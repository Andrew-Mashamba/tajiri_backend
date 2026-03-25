<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->string('query');
            $table->string('search_type')->default('general');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'search_type', 'created_at']);
            $table->index(['query', 'search_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_histories');
    }
};
