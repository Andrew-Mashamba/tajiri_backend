<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Update live_streams status enum and add missing columns
        Schema::table('live_streams', function (Blueprint $table) {
            $table->timestamp('pre_live_started_at')->nullable()->after('scheduled_at');
            $table->boolean('allow_co_hosts')->default(false)->after('allow_gifts');
            $table->integer('unique_viewers')->unsigned()->default(0)->after('total_viewers');
            $table->json('reaction_counts')->nullable()->after('gifts_value');
        });

        // SQLite doesn't support ALTER COLUMN for enum, so we handle status via application logic
        // For MySQL, you would run:
        // DB::statement("ALTER TABLE live_streams MODIFY COLUMN status ENUM('scheduled','pre_live','live','ending','ended','cancelled') DEFAULT 'scheduled'");

        // Add is_currently_watching to stream_viewers
        Schema::table('stream_viewers', function (Blueprint $table) {
            $table->boolean('is_currently_watching')->default(true)->after('watch_duration');
        });

        // Stream notifications table
        Schema::create('stream_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stream_id')->constrained('live_streams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->string('type'); // scheduled, starting_soon, now_live, ended
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamps();

            $table->index(['stream_id', 'user_id']);
            $table->index(['user_id', 'sent_at']);
        });

        // Stream analytics table
        Schema::create('stream_analytics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stream_id')->constrained('live_streams')->cascadeOnDelete();
            $table->timestamp('timestamp');
            $table->integer('viewers_count')->unsigned()->default(0);
            $table->decimal('engagement_rate', 5, 2)->default(0.00);
            $table->json('data')->nullable();
            $table->timestamps();

            $table->index(['stream_id', 'timestamp']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stream_analytics');
        Schema::dropIfExists('stream_notifications');

        Schema::table('stream_viewers', function (Blueprint $table) {
            $table->dropColumn('is_currently_watching');
        });

        Schema::table('live_streams', function (Blueprint $table) {
            $table->dropColumn(['pre_live_started_at', 'allow_co_hosts', 'unique_viewers', 'reaction_counts']);
        });
    }
};
