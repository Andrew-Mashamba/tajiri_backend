<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            if (!Schema::hasColumn('posts', 'quote_from_post_id')) {
                $table->unsignedBigInteger('quote_from_post_id')->nullable();
                $table->index('quote_from_post_id');
            }
            if (!Schema::hasColumn('posts', 'duration_seconds')) {
                // Generic video duration (audio_duration already exists for audio posts).
                $table->unsignedInteger('duration_seconds')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            if (Schema::hasColumn('posts', 'quote_from_post_id')) {
                $table->dropIndex(['quote_from_post_id']);
                $table->dropColumn('quote_from_post_id');
            }
            if (Schema::hasColumn('posts', 'duration_seconds')) {
                $table->dropColumn('duration_seconds');
            }
        });
    }
};
