<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trending_digests', function (Blueprint $table) {
            $table->id();
            $table->text('headline_sw');
            $table->text('headline_en');
            $table->jsonb('stories');
            $table->string('mood', 30)->nullable();
            $table->timestamp('generated_at')->useCurrent();
            $table->timestamp('valid_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trending_digests');
    }
};
