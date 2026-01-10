<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SecondarySchoolSeeder extends Seeder
{
    public function run(): void
    {
        $jsonPath = database_path('seeders/tanzania_secondary_schools.json');

        if (!file_exists($jsonPath)) {
            $this->command->error("Secondary schools JSON file not found at: {$jsonPath}");
            return;
        }

        $data = json_decode(file_get_contents($jsonPath), true);

        if (!$data || !isset($data['schools'])) {
            $this->command->error("Invalid JSON structure");
            return;
        }

        $schools = $data['schools'];
        $this->command->info("Importing {$data['total_schools']} secondary schools...");

        // Clear existing data
        DB::table('secondary_schools')->truncate();

        // Insert in chunks for better performance
        $chunks = array_chunk($schools, 500);
        $bar = $this->command->getOutput()->createProgressBar(count($chunks));

        foreach ($chunks as $chunk) {
            $records = [];
            foreach ($chunk as $school) {
                $records[] = [
                    'code' => $school['code'],
                    'name' => $school['name'],
                    'region_code' => $school['region_code'] ?? null,
                    'district_code' => $school['district_code'] ?? null,
                    'type' => $school['type'] ?? 'unknown',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            DB::table('secondary_schools')->insert($records);
            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info("Successfully imported " . count($schools) . " secondary schools!");
    }
}
