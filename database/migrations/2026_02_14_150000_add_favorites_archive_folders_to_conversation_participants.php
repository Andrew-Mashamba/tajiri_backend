<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->boolean('is_pinned')->default(false)->after('is_muted');
            $table->boolean('is_favorite')->default(false)->after('is_pinned');
            $table->boolean('is_archived')->default(false)->after('is_favorite');
            $table->string('folder', 50)->nullable()->after('is_archived');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->dropColumn(['is_pinned', 'is_favorite', 'is_archived', 'folder']);
        });
    }
};
