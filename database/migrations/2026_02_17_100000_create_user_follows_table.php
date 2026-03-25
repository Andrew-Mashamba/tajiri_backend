<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_follows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('follower_id');
            $table->unsignedBigInteger('following_id');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('follower_id')->references('id')->on('user_profiles')->onDelete('cascade');
            $table->foreign('following_id')->references('id')->on('user_profiles')->onDelete('cascade');

            $table->unique(['follower_id', 'following_id']);
            $table->index(['following_id', 'created_at']);
            $table->index(['follower_id', 'created_at']);
        });

        Schema::table('user_profiles', function (Blueprint $table) {
            $table->unsignedInteger('followers_count')->default(0)->after('friends_count');
            $table->unsignedInteger('following_count')->default(0)->after('followers_count');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_follows');

        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn(['followers_count', 'following_count']);
        });
    }
};
