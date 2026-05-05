<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop defaults, change type, set new defaults
        foreach (['media_types', 'hashtags', 'mentions'] as $col) {
            DB::statement("ALTER TABLE content_documents ALTER COLUMN {$col} DROP DEFAULT");
            DB::statement("ALTER TABLE content_documents ALTER COLUMN {$col} TYPE jsonb USING to_json({$col}::text[])::jsonb");
            DB::statement("ALTER TABLE content_documents ALTER COLUMN {$col} SET DEFAULT '[]'::jsonb");
        }

        // Recreate GIN index for jsonb (drop old one first)
        DB::statement('DROP INDEX IF EXISTS idx_cd_hashtags');
        DB::statement('CREATE INDEX idx_cd_hashtags ON content_documents USING gin (hashtags)');
    }

    public function down(): void
    {
        foreach (['media_types', 'hashtags', 'mentions'] as $col) {
            DB::statement("ALTER TABLE content_documents ALTER COLUMN {$col} DROP DEFAULT");
            DB::statement("ALTER TABLE content_documents ALTER COLUMN {$col} TYPE VARCHAR[] USING ARRAY(SELECT jsonb_array_elements_text({$col}))");
            DB::statement("ALTER TABLE content_documents ALTER COLUMN {$col} SET DEFAULT '{}'::VARCHAR[]");
        }
        DB::statement('DROP INDEX IF EXISTS idx_cd_hashtags');
        DB::statement('CREATE INDEX idx_cd_hashtags ON content_documents USING gin (hashtags)');
    }
};
