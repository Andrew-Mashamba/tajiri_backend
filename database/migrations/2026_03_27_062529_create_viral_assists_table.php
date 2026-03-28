<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viral_assists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sharer_user_id');
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('creator_id');
            $table->integer('likes_at_share')->default(0);
            $table->integer('likes_at_detection')->default(0);
            $table->boolean('notified')->default(false);
            $table->timestamps();
            $table->unique(['sharer_user_id', 'post_id']);
            $table->index('sharer_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viral_assists');
    }
};
