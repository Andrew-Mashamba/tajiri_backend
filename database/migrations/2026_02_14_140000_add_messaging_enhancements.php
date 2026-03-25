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
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('type')
                ->constrained('groups')->onDelete('set null');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('forward_message_id')->nullable()->after('reply_to_id')
                ->constrained('messages')->onDelete('set null');
            $table->timestamp('edited_at')->nullable()->after('read_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['forward_message_id']);
            $table->dropColumn(['forward_message_id', 'edited_at']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['group_id']);
            $table->dropColumn('group_id');
        });
    }
};
