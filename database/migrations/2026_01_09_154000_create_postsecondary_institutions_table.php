<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postsecondary_institutions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('region')->nullable();
            $table->enum('type', ['government', 'private', 'unknown'])->default('unknown');
            $table->enum('category', [
                'vocational_training',
                'teacher_training',
                'health_medical',
                'technical_polytechnic',
                'agricultural',
                'police_military',
                'folk_development',
                'business_professional'
            ]);
            $table->timestamps();

            $table->index('name');
            $table->index('category');
            $table->index('region');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postsecondary_institutions');
    }
};
