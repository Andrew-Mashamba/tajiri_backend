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
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();

            // Bio Information
            $table->string('first_name');
            $table->string('last_name');
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female'])->nullable();

            // Phone (unique identifier)
            $table->string('phone_number')->unique();
            $table->boolean('is_phone_verified')->default(false);

            // Location
            $table->unsignedBigInteger('region_id')->nullable();
            $table->string('region_name')->nullable();
            $table->unsignedBigInteger('district_id')->nullable();
            $table->string('district_name')->nullable();
            $table->unsignedBigInteger('ward_id')->nullable();
            $table->string('ward_name')->nullable();
            $table->unsignedBigInteger('street_id')->nullable();
            $table->string('street_name')->nullable();

            // Primary School
            $table->unsignedBigInteger('primary_school_id')->nullable();
            $table->string('primary_school_code')->nullable();
            $table->string('primary_school_name')->nullable();
            $table->string('primary_school_type')->nullable();
            $table->integer('primary_graduation_year')->nullable();

            // Secondary School (O-Level)
            $table->unsignedBigInteger('secondary_school_id')->nullable();
            $table->string('secondary_school_code')->nullable();
            $table->string('secondary_school_name')->nullable();
            $table->string('secondary_school_type')->nullable();
            $table->integer('secondary_graduation_year')->nullable();

            // A-Level
            $table->unsignedBigInteger('alevel_school_id')->nullable();
            $table->string('alevel_school_code')->nullable();
            $table->string('alevel_school_name')->nullable();
            $table->string('alevel_school_type')->nullable();
            $table->integer('alevel_graduation_year')->nullable();
            $table->string('alevel_combination_code')->nullable();
            $table->string('alevel_combination_name')->nullable();
            $table->json('alevel_subjects')->nullable();

            // Post-Secondary
            $table->unsignedBigInteger('postsecondary_id')->nullable();
            $table->string('postsecondary_code')->nullable();
            $table->string('postsecondary_name')->nullable();
            $table->string('postsecondary_type')->nullable();
            $table->integer('postsecondary_graduation_year')->nullable();

            // University
            $table->unsignedBigInteger('university_id')->nullable();
            $table->string('university_code')->nullable();
            $table->string('university_name')->nullable();
            $table->unsignedBigInteger('programme_id')->nullable();
            $table->string('programme_name')->nullable();
            $table->string('degree_level')->nullable();
            $table->integer('university_graduation_year')->nullable();
            $table->boolean('is_current_student')->default(false);

            // Employer
            $table->unsignedBigInteger('employer_id')->nullable();
            $table->string('employer_code')->nullable();
            $table->string('employer_name')->nullable();
            $table->string('employer_sector')->nullable();
            $table->string('employer_ownership')->nullable();
            $table->boolean('is_custom_employer')->default(false);

            $table->timestamps();

            // Indexes
            $table->index('phone_number');
            $table->index('region_id');
            $table->index('district_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
