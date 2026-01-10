<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UniversitySeeder extends Seeder
{
    public function run(): void
    {
        $jsonPath = database_path('seeders/tanzania_universities.json');

        if (!file_exists($jsonPath)) {
            $this->command->error("Universities JSON not found");
            return;
        }

        $data = json_decode(file_get_contents($jsonPath), true);
        $institutions = $data['institutions'];

        $this->command->info("Importing {$data['total_institutions']} universities...");

        DB::table('universities')->truncate();

        $records = [];
        foreach ($institutions as $inst) {
            $records[] = [
                'code' => $inst['code'],
                'name' => $inst['name'],
                'acronym' => $inst['acronym'] ?? null,
                'region' => $inst['region'],
                'ownership' => $inst['ownership'],
                'category' => $inst['category'],
                'type_label' => $inst['type_label'],
                'parent' => $inst['parent'] ?? null,
                'status' => $inst['status'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('universities')->insert($records);
        $this->command->info("Imported " . count($records) . " universities!");
    }
}
