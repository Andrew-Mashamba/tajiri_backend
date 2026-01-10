<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // Only add columns that don't already exist

            // Post status: draft, scheduled, published, archived
            if (!Schema::hasColumn('posts', 'status')) {
                $table->string('status')->default('published')->after('post_type');
            }

            // scheduled_at already exists from previous migration
            // Just need to add published_at and draft_id

            if (!Schema::hasColumn('posts', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('scheduled_at');
            }

            // Draft reference (if post was created from a draft)
            if (!Schema::hasColumn('posts', 'draft_id')) {
                $table->foreignId('draft_id')->nullable()->after('published_at');
            }
        });

        // Add index using raw SQL with IF NOT EXISTS
        try {
            DB::statement('CREATE INDEX IF NOT EXISTS posts_status_index ON posts (status)');
        } catch (\Exception $e) {
            // Index may already exist, ignore
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop index
        try {
            DB::statement('DROP INDEX IF EXISTS posts_status_index');
        } catch (\Exception $e) {
            // Ignore
        }

        Schema::table('posts', function (Blueprint $table) {
            // Drop columns
            $columns = [];
            if (Schema::hasColumn('posts', 'status')) {
                $columns[] = 'status';
            }
            if (Schema::hasColumn('posts', 'published_at')) {
                $columns[] = 'published_at';
            }
            if (Schema::hasColumn('posts', 'draft_id')) {
                $columns[] = 'draft_id';
            }

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
