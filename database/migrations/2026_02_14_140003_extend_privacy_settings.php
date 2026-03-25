<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->string('read_receipts_visibility', 20)->default('everyone')->after('last_seen_visibility');
            $table->string('online_status_visibility', 20)->default('everyone')->after('read_receipts_visibility');
            $table->string('profile_photo_visibility', 20)->default('everyone')->after('online_status_visibility');
            $table->string('about_visibility', 20)->default('everyone')->after('profile_photo_visibility');
            $table->string('status_visibility', 20)->default('everyone')->after('about_visibility');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'read_receipts_visibility',
                'online_status_visibility',
                'profile_photo_visibility',
                'about_visibility',
                'status_visibility',
            ]);
        });
    }
};
