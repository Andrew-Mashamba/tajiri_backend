<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add typing status to conversation participants
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->boolean('is_typing')->default(false);
            $table->timestamp('typing_started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->dropColumn(['is_typing', 'typing_started_at']);
        });
    }
};
