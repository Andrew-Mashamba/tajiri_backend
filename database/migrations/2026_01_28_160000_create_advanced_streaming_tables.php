<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add health monitoring columns to live_streams
        Schema::table('live_streams', function (Blueprint $table) {
            $table->tinyInteger('beauty_filter_level')->unsigned()->default(50)->after('status');
            $table->string('network_quality', 20)->default('good')->after('beauty_filter_level');
            $table->integer('average_bitrate')->unsigned()->default(0)->after('network_quality');
            $table->tinyInteger('average_fps')->unsigned()->default(30)->after('average_bitrate');
            $table->integer('total_dropped_frames')->unsigned()->default(0)->after('average_fps');
            $table->decimal('average_latency', 5, 2)->default(0.00)->after('total_dropped_frames');
        });

        // Stream reactions (individual reaction tracking)
        Schema::create('stream_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stream_id')->constrained('live_streams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->string('reaction_type', 20); // heart, fire, clap, wow, laugh, sad
            $table->timestamp('created_at')->useCurrent();

            $table->index(['stream_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        // Stream polls
        Schema::create('stream_polls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stream_id')->constrained('live_streams')->cascadeOnDelete();
            $table->string('question', 255);
            $table->boolean('is_closed')->default(false);
            $table->foreignId('created_by')->constrained('user_profiles')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();

            $table->index(['stream_id', 'is_closed']);
        });

        // Stream poll options
        Schema::create('stream_poll_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('stream_polls')->cascadeOnDelete();
            $table->string('text', 100);
            $table->integer('votes')->unsigned()->default(0);

            $table->index('poll_id');
        });

        // Stream poll votes
        Schema::create('stream_poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('stream_polls')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('stream_poll_options')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['poll_id', 'user_id']);
            $table->index('option_id');
        });

        // Stream questions (Q&A mode)
        Schema::create('stream_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stream_id')->constrained('live_streams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->text('question');
            $table->integer('upvotes')->unsigned()->default(0);
            $table->boolean('is_answered')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('answered_at')->nullable();

            $table->index(['stream_id', 'upvotes']);
            $table->index(['stream_id', 'is_answered']);
        });

        // Stream question upvotes
        Schema::create('stream_question_upvotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('stream_questions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['question_id', 'user_id']);
        });

        // Stream super chats
        Schema::create('stream_super_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stream_id')->constrained('live_streams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('user_profiles')->cascadeOnDelete();
            $table->text('message');
            $table->decimal('amount', 10, 2);
            $table->string('tier', 10); // low, medium, high
            $table->tinyInteger('duration')->unsigned()->default(5); // seconds to display
            $table->string('payment_method', 50)->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['stream_id', 'created_at']);
        });

        // Stream battles (PK battles)
        Schema::create('stream_battles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stream_id_1')->constrained('live_streams')->cascadeOnDelete();
            $table->foreignId('stream_id_2')->constrained('live_streams')->cascadeOnDelete();
            $table->string('status', 20)->default('pending'); // pending, active, ended, cancelled
            $table->integer('score_1')->unsigned()->default(0);
            $table->integer('score_2')->unsigned()->default(0);
            $table->foreignId('winner_stream_id')->nullable()->constrained('live_streams')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['stream_id_1', 'stream_id_2']);
        });

        // Add super_chat_tier to virtual_gifts if not exists
        if (!Schema::hasColumn('virtual_gifts', 'super_chat_tier')) {
            Schema::table('virtual_gifts', function (Blueprint $table) {
                $table->string('super_chat_tier', 10)->nullable()->after('animation_path');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stream_battles');
        Schema::dropIfExists('stream_super_chats');
        Schema::dropIfExists('stream_question_upvotes');
        Schema::dropIfExists('stream_questions');
        Schema::dropIfExists('stream_poll_votes');
        Schema::dropIfExists('stream_poll_options');
        Schema::dropIfExists('stream_polls');
        Schema::dropIfExists('stream_reactions');

        if (Schema::hasColumn('virtual_gifts', 'super_chat_tier')) {
            Schema::table('virtual_gifts', function (Blueprint $table) {
                $table->dropColumn('super_chat_tier');
            });
        }

        Schema::table('live_streams', function (Blueprint $table) {
            $table->dropColumn([
                'beauty_filter_level',
                'network_quality',
                'average_bitrate',
                'average_fps',
                'total_dropped_frames',
                'average_latency',
            ]);
        });
    }
};
