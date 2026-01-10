<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SchoolSeeder extends Seeder
{
    public function run(): void
    {
        $jsonPath = database_path('seeders/tanzania_primary_schools.json');

        if (!file_exists($jsonPath)) {
            $this->command->error("Schools JSON file not found at: {$jsonPath}");
            return;
        }

        $data = json_decode(file_get_contents($jsonPath), true);

        if (!$data || !isset($data['schools'])) {
            $this->command->error("Invalid JSON structure");
            return;
        }

        $schools = $data['schools'];
        $this->command->info("Importing {$data['total_schools']} schools...");

        // Clear existing data
        DB::table('schools')->truncate();

        // Insert in chunks for better performance
        $chunks = array_chunk($schools, 500);
        $bar = $this->command->getOutput()->createProgressBar(count($chunks));

        foreach ($chunks as $chunk) {
            $records = [];
            foreach ($chunk as $school) {
                $records[] = [
                    'code' => $school['code'],
                    'name' => $school['name'],
                    'region' => $school['region'],
                    'region_code' => $school['region_code'],
                    'district' => $school['district'],
                    'district_code' => $school['district_code'],
                    'type' => $this->determineSchoolType($school['name']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            DB::table('schools')->insert($records);
            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info("Successfully imported " . count($schools) . " schools!");
    }

    private function determineSchoolType(string $name): string
    {
        $nameLower = strtolower($name);

        // Check for private indicators
        $privateIndicators = [
            'private', 'academy', 'international', 'english medium',
            'montessori', 'college', 'preparatory', 'prep school',
            'christian', 'islamic', 'muslim', 'catholic', 'lutheran',
            'adventist', 'anglican', 'methodist', 'baptist',
        ];

        foreach ($privateIndicators as $indicator) {
            if (str_contains($nameLower, $indicator)) {
                return 'private';
            }
        }

        // Most schools from NECTA PSLE are government schools
        return 'government';
    }
}
