<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->string('profile_visibility')->default('everyone')->after('last_active_at');
            $table->string('who_can_message')->default('everyone')->after('profile_visibility');
            $table->string('who_can_see_posts')->default('everyone')->after('who_can_message');
            $table->string('last_seen_visibility')->default('everyone')->after('who_can_see_posts');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'profile_visibility',
                'who_can_message',
                'who_can_see_posts',
                'last_seen_visibility',
            ]);
        });
    }
};
