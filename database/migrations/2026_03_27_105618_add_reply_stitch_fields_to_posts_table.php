<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->unsignedBigInteger('reply_to_post_id')->nullable()->after('original_post_id');
            $table->unsignedBigInteger('stitch_from_post_id')->nullable()->after('reply_to_post_id');
            $table->string('reply_layout', 20)->nullable()->after('stitch_from_post_id');
            $table->unsignedInteger('stitch_trim_start_ms')->nullable()->after('reply_layout');
            $table->unsignedInteger('stitch_trim_end_ms')->nullable()->after('stitch_trim_start_ms');

            $table->foreign('reply_to_post_id')->references('id')->on('posts')->onDelete('set null');
            $table->foreign('stitch_from_post_id')->references('id')->on('posts')->onDelete('set null');

            $table->index('reply_to_post_id');
            $table->index('stitch_from_post_id');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropForeign(['reply_to_post_id']);
            $table->dropForeign(['stitch_from_post_id']);
            $table->dropColumn([
                'reply_to_post_id',
                'stitch_from_post_id',
                'reply_layout',
                'stitch_trim_start_ms',
                'stitch_trim_end_ms',
            ]);
        });
    }
};
