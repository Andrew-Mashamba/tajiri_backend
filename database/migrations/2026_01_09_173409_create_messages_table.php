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
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->onDelete('cascade');
            $table->foreignId('sender_id')->constrained('user_profiles')->onDelete('cascade');
            $table->text('content')->nullable();
            $table->enum('message_type', ['text', 'image', 'video', 'audio', 'document', 'location', 'contact'])->default('text');
            $table->string('media_path')->nullable();
            $table->string('media_type')->nullable();
            $table->foreignId('reply_to_id')->nullable()->constrained('messages')->onDelete('set null');
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['conversation_id', 'created_at']);
            $table->index('sender_id');
        });

        // Add foreign key for last_message_id in conversations
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreign('last_message_id')->references('id')->on('messages')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['last_message_id']);
        });
        Schema::dropIfExists('messages');
    }
};
