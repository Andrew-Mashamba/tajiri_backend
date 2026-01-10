<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Calls table
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->string('call_id')->unique(); // Unique identifier for WebRTC
            $table->foreignId('caller_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->foreignId('callee_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->string('type'); // voice, video
            $table->string('status')->default('pending'); // pending, ringing, answered, ended, missed, declined, busy
            $table->timestamp('started_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->integer('duration')->nullable(); // In seconds
            $table->string('end_reason')->nullable(); // completed, missed, declined, busy, network_error
            $table->json('quality_metrics')->nullable(); // Store call quality data
            $table->timestamps();

            $table->index(['caller_id', 'status']);
            $table->index(['callee_id', 'status']);
        });

        // Group calls
        Schema::create('group_calls', function (Blueprint $table) {
            $table->id();
            $table->string('call_id')->unique();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('initiated_by')->constrained('user_profiles')->cascadeOnDelete();
            $table->string('type'); // voice, video
            $table->string('status')->default('active'); // active, ended
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->integer('max_participants')->default(0);
            $table->timestamps();
        });

        // Group call participants
        Schema::create('group_call_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_call_id')->constrained('group_calls')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->string('status')->default('invited'); // invited, ringing, joined, left, declined
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->boolean('is_muted')->default(false);
            $table->boolean('is_video_off')->default(false);
            $table->timestamps();

            $table->unique(['group_call_id', 'user_id']);
        });

        // Call history/logs for quick access
        Schema::create('call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->foreignId('call_id')->nullable()->constrained('calls')->cascadeOnDelete();
            $table->foreignId('group_call_id')->nullable()->constrained('group_calls')->cascadeOnDelete();
            $table->foreignId('other_user_id')->nullable()->constrained('user_profiles')->cascadeOnDelete();
            $table->string('type'); // voice, video
            $table->string('direction'); // incoming, outgoing
            $table->string('status'); // answered, missed, declined
            $table->integer('duration')->nullable();
            $table->timestamp('call_time');
            $table->timestamps();

            $table->index(['user_id', 'call_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_logs');
        Schema::dropIfExists('group_call_participants');
        Schema::dropIfExists('group_calls');
        Schema::dropIfExists('calls');
    }
};
