<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add signaling/group-feature columns to group_call_participants
        Schema::table('group_call_participants', function (Blueprint $table) {
            $table->string('role')->default('participant')->after('user_id'); // caller, callee, participant
            $table->timestamp('invited_at')->nullable()->after('left_at');
            $table->timestamp('hand_raised_at')->nullable()->after('is_video_off');
        });

        // Add call_session_id to messages for missed-call voice messages
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('call_session_id')->nullable()->after('forward_message_id')
                ->constrained('calls')->nullOnDelete();
        });

        // Expand message_type CHECK constraint to include missed_call_voice
        DB::statement("ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_message_type_check");
        DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_message_type_check CHECK (message_type::text = ANY (ARRAY['text','image','video','audio','document','location','contact','missed_call_voice']::text[]))");

        // Scheduled calls table
        Schema::create('scheduled_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->string('type'); // voice, video
            $table->timestamp('scheduled_at');
            $table->string('title')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->foreignId('started_call_id')->nullable()->constrained('calls')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['creator_id', 'scheduled_at']);
            $table->index('scheduled_at');
        });

        // Scheduled call invitees
        Schema::create('scheduled_call_invitees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheduled_call_id')->constrained('scheduled_calls')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['scheduled_call_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_call_invitees');
        Schema::dropIfExists('scheduled_calls');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('call_session_id');
        });

        // Restore original CHECK constraint
        DB::statement("ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_message_type_check");
        DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_message_type_check CHECK (message_type::text = ANY (ARRAY['text','image','video','audio','document','location','contact']::text[]))");

        Schema::table('group_call_participants', function (Blueprint $table) {
            $table->dropColumn(['role', 'invited_at', 'hand_raised_at']);
        });
    }
};
