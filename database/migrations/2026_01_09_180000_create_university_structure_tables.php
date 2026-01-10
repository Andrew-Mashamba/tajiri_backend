<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Degree levels lookup table
        Schema::create('degree_levels', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->integer('duration_years');
            $table->timestamps();
        });

        // Universities table (detailed)
        Schema::create('universities_detailed', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('acronym', 20)->nullable();
            $table->enum('type', [
                'public_university',
                'private_university',
                'public_college',
                'private_college',
                'public_institute',
                'private_institute'
            ]);
            $table->string('region')->nullable();
            $table->integer('established')->nullable();
            $table->string('website')->nullable();
            $table->string('parent_university')->nullable(); // For constituent colleges
            $table->timestamps();

            $table->index('name');
            $table->index('acronym');
            $table->index('type');
            $table->index('region');
        });

        // Campuses table
        Schema::create('university_campuses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('location')->nullable();
            $table->foreignId('university_id')->constrained('universities_detailed')->onDelete('cascade');
            $table->timestamps();

            $table->index('name');
        });

        // Colleges/Schools/Faculties/Institutes table
        Schema::create('university_colleges', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->enum('type', ['college', 'school', 'faculty', 'institute'])->default('college');
            $table->foreignId('university_id')->constrained('universities_detailed')->onDelete('cascade');
            $table->timestamps();

            $table->index('name');
            $table->index('type');
        });

        // Departments table
        Schema::create('university_departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30);
            $table->string('name');
            $table->foreignId('college_id')->constrained('university_colleges')->onDelete('cascade');
            $table->timestamps();

            $table->index('name');
            $table->unique(['code', 'college_id']);
        });

        // Programmes table
        Schema::create('university_programmes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30);
            $table->string('name');
            $table->string('level_code', 20); // References degree_levels.code
            $table->integer('duration'); // In years
            $table->foreignId('department_id')->nullable()->constrained('university_departments')->onDelete('cascade');
            $table->foreignId('college_id')->nullable()->constrained('university_colleges')->onDelete('cascade'); // For programmes directly under schools
            $table->foreignId('university_id')->constrained('universities_detailed')->onDelete('cascade');
            $table->timestamps();

            $table->index('name');
            $table->index('level_code');
            $table->unique(['code', 'university_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('university_programmes');
        Schema::dropIfExists('university_departments');
        Schema::dropIfExists('university_colleges');
        Schema::dropIfExists('university_campuses');
        Schema::dropIfExists('universities_detailed');
        Schema::dropIfExists('degree_levels');
    }
};
