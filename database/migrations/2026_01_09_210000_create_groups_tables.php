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
        // Groups table
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('cover_photo_path')->nullable();
            $table->enum('privacy', ['public', 'private', 'secret'])->default('public');
            $table->foreignId('creator_id')->constrained('user_profiles')->onDelete('cascade');
            $table->unsignedInteger('members_count')->default(0);
            $table->unsignedInteger('posts_count')->default(0);
            $table->json('rules')->nullable();
            $table->boolean('requires_approval')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index('privacy');
            $table->index('creator_id');
            $table->fullText(['name', 'description']);
        });

        // Group members table
        Schema::create('group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('user_profiles')->onDelete('cascade');
            $table->enum('role', ['admin', 'moderator', 'member'])->default('member');
            $table->enum('status', ['pending', 'approved', 'banned'])->default('approved');
            $table->timestamp('joined_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('user_profiles')->onDelete('set null');
            $table->timestamps();

            $table->unique(['group_id', 'user_id']);
            $table->index(['group_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        // Group posts table (linking posts to groups)
        Schema::create('group_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->onDelete('cascade');
            $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_announcement')->default(false);
            $table->timestamps();

            $table->unique(['group_id', 'post_id']);
            $table->index(['group_id', 'is_pinned']);
        });

        // Group invitations
        Schema::create('group_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->onDelete('cascade');
            $table->foreignId('inviter_id')->constrained('user_profiles')->onDelete('cascade');
            $table->foreignId('invitee_id')->constrained('user_profiles')->onDelete('cascade');
            $table->enum('status', ['pending', 'accepted', 'declined'])->default('pending');
            $table->timestamps();

            $table->unique(['group_id', 'invitee_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_invitations');
        Schema::dropIfExists('group_posts');
        Schema::dropIfExists('group_members');
        Schema::dropIfExists('groups');
    }
};
