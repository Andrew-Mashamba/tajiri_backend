<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_follows', function (Blueprint $table) {
            $table->unsignedBigInteger('origin_post_id')->nullable()->after('following_id');
            $table->index('origin_post_id');
        });
    }

    public function down(): void
    {
        Schema::table('user_follows', function (Blueprint $table) {
            $table->dropIndex(['origin_post_id']);
            $table->dropColumn('origin_post_id');
        });
    }
};
