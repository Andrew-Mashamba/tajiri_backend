<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->withPersonalTeam()->create();

        User::factory()->withPersonalTeam()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Seed location data
        $this->call([
            LocationSeeder::class,
            SchoolSeeder::class,
            SecondarySchoolSeeder::class,
            AlevelSchoolSeeder::class,
            PostsecondaryInstitutionSeeder::class,
            UniversitySeeder::class,
            BusinessSeeder::class,
            UniversityStructureSeeder::class,
            PostsecondaryStructureSeeder::class,
            AlevelStructureSeeder::class,
        ]);
    }
}
