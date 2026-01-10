<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alevel_schools', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('region_code', 10)->nullable();
            $table->string('district_code', 10)->nullable();
            $table->enum('type', ['government', 'private', 'unknown'])->default('unknown');
            $table->timestamps();

            $table->index('name');
            $table->index('region_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alevel_schools');
    }
};
