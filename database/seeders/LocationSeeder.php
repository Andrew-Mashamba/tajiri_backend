<?php

namespace Database\Seeders;

use App\Models\District;
use App\Models\Region;
use App\Models\Street;
use App\Models\Ward;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $jsonPath = __DIR__ . '/tanzania_locations.json';

        if (!file_exists($jsonPath)) {
            $this->command->error('tanzania_locations.json not found!');
            return;
        }

        $data = json_decode(file_get_contents($jsonPath), true);

        if (!$data || !isset($data['regions'])) {
            $this->command->error('Invalid JSON structure!');
            return;
        }

        $this->command->info('Importing Tanzania locations...');

        // Disable foreign key checks for faster import
        DB::statement('SET session_replication_role = replica;');

        // Clear existing data
        Street::truncate();
        Ward::truncate();
        District::truncate();
        Region::truncate();

        $totalRegions = count($data['regions']);
        $bar = $this->command->getOutput()->createProgressBar($totalRegions);
        $bar->start();

        foreach ($data['regions'] as $regionData) {
            $region = Region::create([
                'name' => $regionData['name'],
                'post_code' => $regionData['post_code'] ?? null,
            ]);

            foreach ($regionData['districts'] as $districtData) {
                $district = District::create([
                    'region_id' => $region->id,
                    'name' => $districtData['name'],
                    'post_code' => $districtData['post_code'] ?? null,
                ]);

                foreach ($districtData['wards'] as $wardData) {
                    $ward = Ward::create([
                        'district_id' => $district->id,
                        'name' => $wardData['name'],
                        'post_code' => $wardData['post_code'] ?? null,
                    ]);

                    // Batch insert streets for performance
                    $streets = array_map(function ($streetName) use ($ward) {
                        return [
                            'ward_id' => $ward->id,
                            'name' => $streetName,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }, $wardData['streets']);

                    if (!empty($streets)) {
                        Street::insert($streets);
                    }
                }
            }

            $bar->advance();
        }

        $bar->finish();

        // Re-enable foreign key checks
        DB::statement('SET session_replication_role = DEFAULT;');

        $this->command->newLine();
        $this->command->info('Import complete!');
        $this->command->info('Regions: ' . Region::count());
        $this->command->info('Districts: ' . District::count());
        $this->command->info('Wards: ' . Ward::count());
        $this->command->info('Streets: ' . Street::count());
    }
}
