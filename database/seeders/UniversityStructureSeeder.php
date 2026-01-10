<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UniversityStructureSeeder extends Seeder
{
    public function run(): void
    {
        $jsonPath = database_path('seeders/tanzania_universities_detailed.json');

        if (!file_exists($jsonPath)) {
            $this->command->error("Universities JSON not found at: {$jsonPath}");
            return;
        }

        $data = json_decode(file_get_contents($jsonPath), true);

        // Seed degree levels
        $this->command->info("Seeding degree levels...");
        DB::table('degree_levels')->truncate();
        foreach ($data['degree_levels'] as $level) {
            DB::table('degree_levels')->insert([
                'code' => $level['code'],
                'name' => $level['name'],
                'duration_years' => $level['duration_years'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->command->info("Seeded " . count($data['degree_levels']) . " degree levels");

        // Clear existing data
        $this->command->info("Clearing existing university data...");
        DB::table('university_programmes')->truncate();
        DB::table('university_departments')->truncate();
        DB::table('university_colleges')->truncate();
        DB::table('university_campuses')->truncate();
        DB::table('universities_detailed')->truncate();

        $totalProgrammes = 0;
        $totalDepartments = 0;
        $totalColleges = 0;
        $totalCampuses = 0;

        foreach ($data['universities'] as $uni) {
            $this->command->info("Importing: {$uni['name']}...");

            // Insert university
            $uniId = DB::table('universities_detailed')->insertGetId([
                'code' => $uni['code'],
                'name' => $uni['name'],
                'acronym' => $uni['acronym'] ?? null,
                'type' => $uni['type'],
                'region' => $uni['region'] ?? null,
                'established' => $uni['established'] ?? null,
                'website' => $uni['website'] ?? null,
                'parent_university' => $uni['parent_university'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Insert campuses
            foreach ($uni['campuses'] ?? [] as $campus) {
                DB::table('university_campuses')->insert([
                    'code' => $campus['code'],
                    'name' => $campus['name'],
                    'location' => $campus['location'] ?? null,
                    'university_id' => $uniId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $totalCampuses++;
            }

            // Process colleges, schools, faculties, and institutes
            $unitTypes = [
                'colleges' => 'college',
                'schools' => 'school',
                'faculties' => 'faculty',
                'institutes' => 'institute',
            ];

            foreach ($unitTypes as $key => $type) {
                foreach ($uni[$key] ?? [] as $unit) {
                    $collegeId = DB::table('university_colleges')->insertGetId([
                        'code' => $uni['code'] . '-' . $unit['code'],
                        'name' => $unit['name'],
                        'type' => $type,
                        'university_id' => $uniId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $totalColleges++;

                    // Process departments if exists
                    foreach ($unit['departments'] ?? [] as $dept) {
                        $deptId = DB::table('university_departments')->insertGetId([
                            'code' => $dept['code'],
                            'name' => $dept['name'],
                            'college_id' => $collegeId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $totalDepartments++;

                        // Insert programmes under department
                        foreach ($dept['programmes'] ?? [] as $prog) {
                            // Make code unique by including dept code
                            $progCode = $unit['code'] . '-' . $dept['code'] . '-' . $prog['code'];
                            DB::table('university_programmes')->insert([
                                'code' => $progCode,
                                'name' => $prog['name'],
                                'level_code' => $prog['level'],
                                'duration' => $prog['duration'],
                                'department_id' => $deptId,
                                'college_id' => $collegeId,
                                'university_id' => $uniId,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                            $totalProgrammes++;
                        }
                    }

                    // Insert programmes directly under college/school/faculty (no department)
                    foreach ($unit['programmes'] ?? [] as $prog) {
                        $progCode = $unit['code'] . '-' . $prog['code'];
                        DB::table('university_programmes')->insert([
                            'code' => $progCode,
                            'name' => $prog['name'],
                            'level_code' => $prog['level'],
                            'duration' => $prog['duration'],
                            'department_id' => null,
                            'college_id' => $collegeId,
                            'university_id' => $uniId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $totalProgrammes++;
                    }
                }
            }
        }

        $this->command->info("=" . str_repeat("=", 60));
        $this->command->info("Import complete!");
        $this->command->info("Universities: " . count($data['universities']));
        $this->command->info("Campuses: {$totalCampuses}");
        $this->command->info("Colleges/Schools/Faculties/Institutes: {$totalColleges}");
        $this->command->info("Departments: {$totalDepartments}");
        $this->command->info("Programmes: {$totalProgrammes}");
        $this->command->info("=" . str_repeat("=", 60));
    }
}
