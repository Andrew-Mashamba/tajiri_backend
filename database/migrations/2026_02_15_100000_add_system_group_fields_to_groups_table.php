<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Auto/Default Groups System
     * --------------------------
     * Adds fields so the system can create and manage groups automatically
     * based on user profile characteristics (school, university, location, etc.).
     *
     * - is_system: marks groups as system-managed (can't be deleted/renamed by users)
     * - system_group_type: categorizes the group (e.g. 'university_programme_year')
     * - system_lookup_key: deterministic key for finding existing groups (unique index)
     * - creator_id: made nullable so system groups don't need a human creator
     */
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            // System group flag
            $table->boolean('is_system')->default(false)->after('requires_approval');

            // Category of the system group
            $table->string('system_group_type', 60)->nullable()->after('is_system');

            // Deterministic lookup key for deduplication
            // e.g. "al:123:cb:PCB:y:2009" or "uni:45:p:89:y:2024"
            $table->string('system_lookup_key', 255)->nullable()->unique()->after('system_group_type');

            // Index for querying system groups by type
            $table->index(['is_system', 'system_group_type']);
        });

        // Make creator_id nullable for system groups
        // PostgreSQL: alter column directly
        if (config('database.default') === 'pgsql') {
            \DB::statement('ALTER TABLE groups ALTER COLUMN creator_id DROP NOT NULL');
        } else {
            Schema::table('groups', function (Blueprint $table) {
                $table->unsignedBigInteger('creator_id')->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropIndex(['is_system', 'system_group_type']);
            $table->dropUnique(['system_lookup_key']);
            $table->dropColumn(['is_system', 'system_group_type', 'system_lookup_key']);
        });

        // Restore creator_id NOT NULL (only if no null values exist)
        if (config('database.default') === 'pgsql') {
            \DB::statement('ALTER TABLE groups ALTER COLUMN creator_id SET NOT NULL');
        } else {
            Schema::table('groups', function (Blueprint $table) {
                $table->unsignedBigInteger('creator_id')->nullable(false)->change();
            });
        }
    }
};
