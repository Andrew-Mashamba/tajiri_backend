<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE posts DROP CONSTRAINT IF EXISTS posts_post_type_check');
        DB::statement("ALTER TABLE posts ADD CONSTRAINT posts_post_type_check CHECK (post_type IN ('text', 'photo', 'video', 'short_video', 'audio', 'image_text', 'audio_text', 'poll', 'shared'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE posts DROP CONSTRAINT IF EXISTS posts_post_type_check');
        DB::statement("ALTER TABLE posts ADD CONSTRAINT posts_post_type_check CHECK (post_type IN ('text', 'photo', 'video', 'short_video', 'audio', 'poll', 'shared'))");
    }
};
