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
            $table->string('username', 50)->unique()->nullable()->after('last_name');
            $table->string('bio', 500)->nullable()->after('is_phone_verified');
            $table->string('profile_photo_path')->nullable()->after('bio');
            $table->string('cover_photo_path')->nullable()->after('profile_photo_path');
            $table->json('interests')->nullable()->after('cover_photo_path');
            $table->string('relationship_status')->nullable()->after('interests');
            $table->integer('friends_count')->default(0)->after('relationship_status');
            $table->integer('posts_count')->default(0)->after('friends_count');
            $table->integer('photos_count')->default(0)->after('posts_count');
            $table->timestamp('last_active_at')->nullable()->after('photos_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'username',
                'bio',
                'profile_photo_path',
                'cover_photo_path',
                'interests',
                'relationship_status',
                'friends_count',
                'posts_count',
                'photos_count',
                'last_active_at',
            ]);
        });
    }
};
