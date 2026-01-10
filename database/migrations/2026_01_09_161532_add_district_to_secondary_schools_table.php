<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add district and region columns
        Schema::table('secondary_schools', function (Blueprint $table) {
            $table->string('region')->nullable()->after('name');
            $table->string('district')->nullable()->after('region');
        });

        // Populate from primary schools data where district_codes match
        DB::statement("
            UPDATE secondary_schools ss
            SET
                region = ps.region,
                district = ps.district
            FROM (
                SELECT DISTINCT region_code, district_code, region, district
                FROM schools
                WHERE region IS NOT NULL AND district IS NOT NULL
            ) ps
            WHERE ss.region_code = ps.region_code
            AND ss.district_code = ps.district_code
        ");

        // For remaining schools without district, use region name from region mapping
        $regionNames = [
            '01' => 'Arusha', '02' => 'Dar es Salaam', '03' => 'Dodoma',
            '04' => 'Iringa', '05' => 'Kagera', '06' => 'Kaskazini Pemba',
            '07' => 'Kaskazini Unguja', '08' => 'Kigoma', '09' => 'Kilimanjaro',
            '10' => 'Kusini Pemba', '11' => 'Kusini Unguja', '12' => 'Lindi',
            '13' => 'Mara', '14' => 'Mbeya', '15' => 'Mjini Magharibi',
            '16' => 'Morogoro', '17' => 'Mtwara', '18' => 'Mwanza',
            '19' => 'Pwani', '20' => 'Rukwa', '21' => 'Ruvuma',
            '22' => 'Shinyanga', '23' => 'Singida', '24' => 'Tabora',
            '25' => 'Tanga', '26' => 'Manyara', '27' => 'Geita',
            '28' => 'Katavi', '29' => 'Njombe', '30' => 'Simiyu', '31' => 'Songwe',
        ];

        foreach ($regionNames as $code => $name) {
            DB::table('secondary_schools')
                ->whereNull('region')
                ->where('region_code', $code)
                ->update(['region' => $name]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('secondary_schools', function (Blueprint $table) {
            $table->dropColumn(['region', 'district']);
        });
    }
};
