<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A-Level Subjects (PHY, CHEM, BIO, MATH, etc.)
        Schema::create('alevel_subjects', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->enum('category', ['science', 'social_science', 'language', 'arts', 'religious', 'compulsory']);
            $table->timestamps();
        });

        // A-Level Subject Combinations (PCM, PCB, HGL, etc.)
        Schema::create('alevel_combinations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->enum('category', ['science', 'business', 'arts', 'language', 'religious']);
            $table->enum('popularity', ['high', 'medium', 'low'])->default('medium');
            $table->json('careers')->nullable();
            $table->timestamps();
        });

        // Pivot: Combination <-> Subjects (each combination has 3 subjects)
        Schema::create('alevel_combination_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('combination_id')->constrained('alevel_combinations')->onDelete('cascade');
            $table->foreignId('subject_id')->constrained('alevel_subjects')->onDelete('cascade');
            $table->unique(['combination_id', 'subject_id']);
        });

        // Pivot: School <-> Combinations (which combinations each school offers)
        Schema::create('alevel_school_combinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('alevel_schools')->onDelete('cascade');
            $table->foreignId('combination_id')->constrained('alevel_combinations')->onDelete('cascade');
            $table->unique(['school_id', 'combination_id']);
        });

        // Form levels for A-Level (Form 5, Form 6)
        Schema::create('alevel_form_levels', function (Blueprint $table) {
            $table->id();
            $table->string('code', 5)->unique();
            $table->string('name');
            $table->tinyInteger('year');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alevel_school_combinations');
        Schema::dropIfExists('alevel_combination_subjects');
        Schema::dropIfExists('alevel_form_levels');
        Schema::dropIfExists('alevel_combinations');
        Schema::dropIfExists('alevel_subjects');
    }
};
