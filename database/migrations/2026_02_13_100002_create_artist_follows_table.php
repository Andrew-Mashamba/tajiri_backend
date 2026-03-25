<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artist_follows', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->foreignId('artist_id')->constrained('music_artists')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['user_id', 'artist_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_follows');
    }
};
