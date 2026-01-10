<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PostsecondaryInstitutionSeeder extends Seeder
{
    public function run(): void
    {
        $jsonPath = database_path('seeders/tanzania_postsecondary_institutions.json');

        if (!file_exists($jsonPath)) {
            $this->command->error("Post-secondary institutions JSON not found");
            return;
        }

        $data = json_decode(file_get_contents($jsonPath), true);
        $institutions = $data['institutions'];

        $this->command->info("Importing {$data['total_institutions']} post-secondary institutions...");

        DB::table('postsecondary_institutions')->truncate();

        $records = [];
        foreach ($institutions as $inst) {
            $records[] = [
                'code' => $inst['code'],
                'name' => $inst['name'],
                'region' => $inst['region'] ?? null,
                'type' => $inst['type'] ?? 'unknown',
                'category' => $inst['category'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('postsecondary_institutions')->insert($records);
        $this->command->info("Imported " . count($records) . " institutions!");
    }
}
