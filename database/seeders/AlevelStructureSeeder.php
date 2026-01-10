<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AlevelStructureSeeder extends Seeder
{
    public function run(): void
    {
        $jsonPath = database_path('seeders/tanzania_alevel_detailed.json');

        if (!file_exists($jsonPath)) {
            $this->command->error("A-Level JSON not found at: {$jsonPath}");
            return;
        }

        $data = json_decode(file_get_contents($jsonPath), true);

        // Seed form levels
        $this->command->info("Seeding A-Level form levels...");
        DB::table('alevel_form_levels')->truncate();
        foreach ($data['form_levels'] as $level) {
            DB::table('alevel_form_levels')->insert([
                'code' => $level['code'],
                'name' => $level['name'],
                'year' => $level['year'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->command->info("Seeded " . count($data['form_levels']) . " form levels");

        // Seed subjects
        $this->command->info("Seeding A-Level subjects...");
        DB::table('alevel_subjects')->truncate();
        $subjectIds = [];
        foreach ($data['subjects'] as $subject) {
            $id = DB::table('alevel_subjects')->insertGetId([
                'code' => $subject['code'],
                'name' => $subject['name'],
                'category' => $subject['category'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $subjectIds[$subject['code']] = $id;
        }
        $this->command->info("Seeded " . count($data['subjects']) . " subjects");

        // Seed combinations
        $this->command->info("Seeding A-Level combinations...");
        DB::table('alevel_combination_subjects')->truncate();
        DB::table('alevel_combinations')->truncate();
        $combinationIds = [];
        foreach ($data['combinations'] as $combo) {
            $id = DB::table('alevel_combinations')->insertGetId([
                'code' => $combo['code'],
                'name' => $combo['name'],
                'category' => $combo['category'],
                'popularity' => $combo['popularity'],
                'careers' => json_encode($combo['careers']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $combinationIds[$combo['code']] = $id;

            // Link subjects to combination
            foreach ($combo['subjects'] as $subjectCode) {
                if (isset($subjectIds[$subjectCode])) {
                    DB::table('alevel_combination_subjects')->insert([
                        'combination_id' => $id,
                        'subject_id' => $subjectIds[$subjectCode],
                    ]);
                }
            }
        }
        $this->command->info("Seeded " . count($data['combinations']) . " combinations");

        // Seed school-combination relationships
        $this->command->info("Seeding school-combination relationships...");
        DB::table('alevel_school_combinations')->truncate();
        $totalRelationships = 0;

        foreach ($data['schools'] as $school) {
            // Find the school in the database
            $schoolRecord = DB::table('alevel_schools')->where('code', $school['code'])->first();

            if (!$schoolRecord) {
                $this->command->warn("School not found: {$school['code']} - {$school['name']}");
                continue;
            }

            foreach ($school['combinations'] as $comboCode) {
                if (isset($combinationIds[$comboCode])) {
                    DB::table('alevel_school_combinations')->insert([
                        'school_id' => $schoolRecord->id,
                        'combination_id' => $combinationIds[$comboCode],
                    ]);
                    $totalRelationships++;
                }
            }
        }

        $this->command->info("=" . str_repeat("=", 60));
        $this->command->info("A-Level structure seeding complete!");
        $this->command->info("Form Levels: " . count($data['form_levels']));
        $this->command->info("Subjects: " . count($data['subjects']));
        $this->command->info("Combinations: " . count($data['combinations']));
        $this->command->info("School-Combination Links: {$totalRelationships}");
        $this->command->info("=" . str_repeat("=", 60));
    }
}
