<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_media', function (Blueprint $table) {
            // Dominant color extracted from image (hex, e.g. '#3A5F8C')
            $table->string('dominant_color', 7)->nullable()->after('thumbnail_path');
            // Grid thumbnail path (small ~300px square for profile grid)
            $table->string('grid_thumbnail_path', 255)->nullable()->after('dominant_color');
        });
    }

    public function down(): void
    {
        Schema::table('post_media', function (Blueprint $table) {
            $table->dropColumn(['dominant_color', 'grid_thumbnail_path']);
        });
    }
};
