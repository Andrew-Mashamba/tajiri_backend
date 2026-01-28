<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the old CHECK constraint that only allows: scheduled, live, ended, cancelled
        DB::statement('ALTER TABLE live_streams DROP CONSTRAINT IF EXISTS live_streams_status_check');

        // Add new CHECK constraint with all statuses: scheduled, pre_live, live, ending, ended, cancelled
        DB::statement("ALTER TABLE live_streams ADD CONSTRAINT live_streams_status_check CHECK (status IN ('scheduled', 'pre_live', 'live', 'ending', 'ended', 'cancelled'))");
    }

    public function down(): void
    {
        // Revert to original constraint
        DB::statement('ALTER TABLE live_streams DROP CONSTRAINT IF EXISTS live_streams_status_check');
        DB::statement("ALTER TABLE live_streams ADD CONSTRAINT live_streams_status_check CHECK (status IN ('scheduled', 'live', 'ended', 'cancelled'))");
    }
};
