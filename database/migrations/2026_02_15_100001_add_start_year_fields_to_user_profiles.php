<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add starting year fields for each education level.
     *
     * Used to calculate which year a current/ongoing student is in,
     * and to assign them to "Intake YYYY" groups.
     */
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->unsignedSmallInteger('primary_start_year')->nullable()->after('primary_school_type');
            $table->unsignedSmallInteger('secondary_start_year')->nullable()->after('secondary_school_type');
            $table->unsignedSmallInteger('alevel_start_year')->nullable()->after('alevel_school_type');
            $table->unsignedSmallInteger('postsecondary_start_year')->nullable()->after('postsecondary_type');
            $table->unsignedSmallInteger('university_start_year')->nullable()->after('programme_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'primary_start_year',
                'secondary_start_year',
                'alevel_start_year',
                'postsecondary_start_year',
                'university_start_year',
            ]);
        });
    }
};
