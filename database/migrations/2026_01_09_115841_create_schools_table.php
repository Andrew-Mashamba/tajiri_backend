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
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('region');
            $table->string('region_code', 10);
            $table->string('district');
            $table->string('district_code', 10);
            $table->enum('type', ['government', 'private', 'unknown'])->default('unknown');
            $table->timestamps();

            $table->index(['region', 'district']);
            $table->index('name');
            $table->index('district_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schools');
    }
};
