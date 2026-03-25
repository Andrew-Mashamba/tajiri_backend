<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make created_by nullable so system group conversations
     * (which have no human creator) can be created.
     */
    public function up(): void
    {
        if (config('database.default') === 'pgsql') {
            DB::statement('ALTER TABLE conversations ALTER COLUMN created_by DROP NOT NULL');
        } else {
            Schema::table('conversations', function ($table) {
                $table->unsignedBigInteger('created_by')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (config('database.default') === 'pgsql') {
            DB::statement('ALTER TABLE conversations ALTER COLUMN created_by SET NOT NULL');
        } else {
            Schema::table('conversations', function ($table) {
                $table->unsignedBigInteger('created_by')->nullable(false)->change();
            });
        }
    }
};
