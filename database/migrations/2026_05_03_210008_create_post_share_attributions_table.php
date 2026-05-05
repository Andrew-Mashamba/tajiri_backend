<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_share_attributions', function (Blueprint $table) {
            $table->id();
            $table->uuid('share_uid')->unique();
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('sharer_user_id');
            $table->timestampTz('expires_at');     // 30 days
            $table->timestampsTz();
            $table->index(['post_id', 'sharer_user_id']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_share_attributions');
    }
};
