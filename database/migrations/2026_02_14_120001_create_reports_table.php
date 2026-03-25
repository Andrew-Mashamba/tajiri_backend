<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->morphs('reportable');
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->string('reason');
            $table->string('category')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->unique(['reportable_type', 'reportable_id', 'user_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
