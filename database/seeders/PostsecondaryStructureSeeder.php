<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PostsecondaryStructureSeeder extends Seeder
{
    public function run(): void
    {
        $jsonPath = database_path('seeders/tanzania_postsecondary_detailed.json');

        if (!file_exists($jsonPath)) {
            $this->command->error("Post-secondary JSON not found at: {$jsonPath}");
            return;
        }

        $data = json_decode(file_get_contents($jsonPath), true);

        // Seed qualification levels
        $this->command->info("Seeding qualification levels...");
        DB::table('postsecondary_qualification_levels')->truncate();
        foreach ($data['qualification_levels'] as $level) {
            DB::table('postsecondary_qualification_levels')->insert([
                'code' => $level['code'],
                'name' => $level['name'],
                'duration_years' => $level['duration_years'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->command->info("Seeded " . count($data['qualification_levels']) . " qualification levels");

        // Clear existing data
        $this->command->info("Clearing existing post-secondary data...");
        DB::table('postsecondary_programmes')->truncate();
        DB::table('postsecondary_departments')->truncate();
        DB::table('postsecondary_campuses')->truncate();
        DB::table('postsecondary_institutions')->truncate();

        $totalProgrammes = 0;
        $totalDepartments = 0;
        $totalCampuses = 0;

        foreach ($data['institutions'] as $inst) {
            $this->command->info("Importing: {$inst['name']}...");

            // Map type to allowed enum values
            $type = $inst['type'] ?? 'unknown';
            if (str_contains($type, 'government')) {
                $type = 'government';
            } elseif (str_contains($type, 'private')) {
                $type = 'private';
            } else {
                $type = 'unknown';
            }

            // Insert institution
            $instId = DB::table('postsecondary_institutions')->insertGetId([
                'code' => $inst['code'],
                'name' => $inst['name'],
                'acronym' => $inst['acronym'] ?? null,
                'type' => $type,
                'category' => $inst['category'] ?? 'vocational',
                'region' => $inst['region'] ?? null,
                'established' => $inst['established'] ?? null,
                'website' => $inst['website'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Insert campuses
            foreach ($inst['campuses'] ?? [] as $campus) {
                DB::table('postsecondary_campuses')->insert([
                    'code' => $campus['code'],
                    'name' => $campus['name'],
                    'location' => $campus['location'] ?? null,
                    'institution_id' => $instId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $totalCampuses++;
            }

            // Process departments
            foreach ($inst['departments'] ?? [] as $dept) {
                $deptCode = $inst['code'] . '-' . $dept['code'];
                $deptId = DB::table('postsecondary_departments')->insertGetId([
                    'code' => $deptCode,
                    'name' => $dept['name'],
                    'institution_id' => $instId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $totalDepartments++;

                // Insert programmes under department
                foreach ($dept['programmes'] ?? [] as $prog) {
                    $progCode = $inst['code'] . '-' . $dept['code'] . '-' . $prog['code'];
                    DB::table('postsecondary_programmes')->insert([
                        'code' => $progCode,
                        'name' => $prog['name'],
                        'level_code' => $prog['level'],
                        'duration' => $prog['duration'],
                        'department_id' => $deptId,
                        'institution_id' => $instId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $totalProgrammes++;
                }
            }
        }

        $this->command->info("=" . str_repeat("=", 60));
        $this->command->info("Import complete!");
        $this->command->info("Institutions: " . count($data['institutions']));
        $this->command->info("Campuses: {$totalCampuses}");
        $this->command->info("Departments: {$totalDepartments}");
        $this->command->info("Programmes: {$totalProgrammes}");
        $this->command->info("=" . str_repeat("=", 60));
    }
}
