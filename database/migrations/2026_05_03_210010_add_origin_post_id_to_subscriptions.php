<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subscriptions')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                if (!Schema::hasColumn('subscriptions', 'origin_post_id')) {
                    $table->unsignedBigInteger('origin_post_id')->nullable();
                    $table->index('origin_post_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('subscriptions') && Schema::hasColumn('subscriptions', 'origin_post_id')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropIndex(['origin_post_id']);
                $table->dropColumn('origin_post_id');
            });
        }
    }
};
