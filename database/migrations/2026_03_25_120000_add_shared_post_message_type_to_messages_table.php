<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Extend Postgres CHECK constraint for `messages.message_type` to allow `shared_post`.
        DB::statement("ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_message_type_check");
        DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_message_type_check CHECK (message_type::text = ANY (ARRAY['text','image','video','audio','document','location','contact','missed_call_voice','shared_post']::text[]))");
    }

    public function down(): void
    {
        // Restore original allowed values (keep `missed_call_voice`).
        DB::statement("ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_message_type_check");
        DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_message_type_check CHECK (message_type::text = ANY (ARRAY['text','image','video','audio','document','location','contact','missed_call_voice']::text[]))");
    }
};

