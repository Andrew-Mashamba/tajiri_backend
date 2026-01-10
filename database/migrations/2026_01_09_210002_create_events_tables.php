<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Events table
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('cover_photo_path')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('timezone')->default('Africa/Dar_es_Salaam');
            $table->boolean('is_all_day')->default(false);
            $table->string('location_name')->nullable();
            $table->string('location_address')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->boolean('is_online')->default(false);
            $table->string('online_link')->nullable();
            $table->enum('privacy', ['public', 'friends', 'private', 'group'])->default('public');
            $table->string('category')->nullable();
            $table->foreignId('creator_id')->constrained('user_profiles')->onDelete('cascade');
            $table->foreignId('group_id')->nullable()->constrained('groups')->onDelete('cascade');
            $table->foreignId('page_id')->nullable()->constrained('pages')->onDelete('cascade');
            $table->unsignedInteger('going_count')->default(0);
            $table->unsignedInteger('interested_count')->default(0);
            $table->unsignedInteger('not_going_count')->default(0);
            $table->decimal('ticket_price', 10, 2)->nullable();
            $table->string('ticket_currency')->default('TZS');
            $table->string('ticket_link')->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->json('recurrence_rule')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['start_date', 'start_time']);
            $table->index('privacy');
            $table->index('creator_id');
            $table->index('group_id');
            $table->index('page_id');
            $table->fullText(['name', 'description']);
        });

        // Event responses (going, interested, not going)
        Schema::create('event_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('user_profiles')->onDelete('cascade');
            $table->enum('response', ['going', 'interested', 'not_going']);
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
            $table->index(['event_id', 'response']);
        });

        // Event co-hosts
        Schema::create('event_hosts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('user_profiles')->onDelete('cascade');
            $table->foreignId('page_id')->nullable()->constrained('pages')->onDelete('cascade');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
            $table->unique(['event_id', 'page_id']);
        });

        // Event posts (discussions)
        Schema::create('event_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();

            $table->unique(['event_id', 'post_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_posts');
        Schema::dropIfExists('event_hosts');
        Schema::dropIfExists('event_responses');
        Schema::dropIfExists('events');
    }
};
