<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BusinessSeeder extends Seeder
{
    public function run(): void
    {
        $jsonPath = database_path('seeders/tanzania_businesses.json');

        if (!file_exists($jsonPath)) {
            $this->command->error("Businesses JSON not found");
            return;
        }

        $data = json_decode(file_get_contents($jsonPath), true);
        $businesses = $data['businesses'];

        $this->command->info("Importing {$data['metadata']['total_businesses']} businesses...");

        DB::table('businesses')->truncate();

        $records = [];
        foreach ($businesses as $biz) {
            $records[] = [
                'code' => $biz['code'],
                'name' => $biz['name'],
                'acronym' => $biz['acronym'] ?? null,
                'sector' => $biz['sector'],
                'ownership' => $biz['ownership'],
                'category' => $biz['category'],
                'parent' => $biz['parent'] ?? null,
                'region' => $biz['region'] ?? null,
                'ministry' => $biz['ministry'] ?? null,
                'owner' => $biz['owner'] ?? null,
                'isin' => $biz['isin'] ?? null,
                'phone' => $biz['phone'] ?? null,
                'website' => $biz['website'] ?? null,
                'address' => $biz['address'] ?? null,
                'products' => $biz['products'] ?? null,
                'year_established' => $biz['year_established'] ?? null,
                'source' => $biz['source'] ?? null,
                'source_url' => $biz['source_url'] ?? null,
                'description' => $biz['description'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Insert in chunks to avoid memory issues
        foreach (array_chunk($records, 100) as $chunk) {
            DB::table('businesses')->insert($chunk);
        }

        $this->command->info("Imported " . count($records) . " businesses!");
    }
}
