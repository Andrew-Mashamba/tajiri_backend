<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AlevelSchoolSeeder extends Seeder
{
    public function run(): void
    {
        $jsonPath = database_path('seeders/tanzania_alevel_schools.json');

        if (!file_exists($jsonPath)) {
            $this->command->error("A-Level schools JSON not found");
            return;
        }

        $data = json_decode(file_get_contents($jsonPath), true);
        $schools = $data['schools'];

        $this->command->info("Importing {$data['total_schools']} A-Level schools...");

        DB::table('alevel_schools')->truncate();

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
            DB::table('alevel_schools')->insert($records);
            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info("Imported " . count($schools) . " A-Level schools!");
    }
}
