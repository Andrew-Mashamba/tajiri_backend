<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Qualification levels for post-secondary (NVA, NTA, CERT, DIP)
        Schema::create('postsecondary_qualification_levels', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->integer('duration_years');
            $table->timestamps();
        });

        // Add additional columns to existing postsecondary_institutions table
        Schema::table('postsecondary_institutions', function (Blueprint $table) {
            $table->string('acronym', 50)->nullable()->after('name');
            $table->integer('established')->nullable()->after('category');
            $table->string('website')->nullable()->after('established');
            $table->index('type');
        });

        // Campuses for post-secondary institutions
        Schema::create('postsecondary_campuses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('location')->nullable();
            $table->foreignId('institution_id')->constrained('postsecondary_institutions')->onDelete('cascade');
            $table->timestamps();

            $table->index('name');
        });

        // Departments for post-secondary institutions
        Schema::create('postsecondary_departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40);
            $table->string('name');
            $table->foreignId('institution_id')->constrained('postsecondary_institutions')->onDelete('cascade');
            $table->timestamps();

            $table->index('name');
            $table->unique(['code', 'institution_id']);
        });

        // Programmes for post-secondary institutions
        Schema::create('postsecondary_programmes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50);
            $table->string('name');
            $table->string('level_code', 20); // NVA1, NVA2, NVA3, NTA4-8, CERT, DIP
            $table->integer('duration'); // In years
            $table->foreignId('department_id')->constrained('postsecondary_departments')->onDelete('cascade');
            $table->foreignId('institution_id')->constrained('postsecondary_institutions')->onDelete('cascade');
            $table->timestamps();

            $table->index('name');
            $table->index('level_code');
            $table->unique(['code', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postsecondary_programmes');
        Schema::dropIfExists('postsecondary_departments');
        Schema::dropIfExists('postsecondary_campuses');
        Schema::table('postsecondary_institutions', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn(['acronym', 'established', 'website']);
        });
        Schema::dropIfExists('postsecondary_qualification_levels');
    }
};
