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
        Schema::create('secondary_schools', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('region_code', 10)->nullable();
            $table->string('district_code', 10)->nullable();
            $table->enum('type', ['government', 'private', 'unknown'])->default('unknown');
            $table->timestamps();

            $table->index('name');
            $table->index('region_code');
            $table->index('district_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('secondary_schools');
    }
};
