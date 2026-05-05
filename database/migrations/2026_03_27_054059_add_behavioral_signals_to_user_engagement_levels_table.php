<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_engagement_levels', function (Blueprint $table) {
            $table->integer('days_active_14d')->default(0)->after('weekly_actions');
            $table->integer('sessions_14d')->default(0)->after('days_active_14d');
            $table->integer('posts_created_14d')->default(0)->after('sessions_14d');
        });
    }

    public function down(): void
    {
        Schema::table('user_engagement_levels', function (Blueprint $table) {
            $table->dropColumn(['days_active_14d', 'sessions_14d', 'posts_created_14d']);
        });
    }
};
