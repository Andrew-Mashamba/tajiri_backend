<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SampleUsersSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make('password123');
        $now = now();

        $users = [
            // 1. Male, DSM, UDSM graduate, works at CRDB Bank
            [
                'name' => 'Baraka Mwinyi', 'email' => 'baraka.mwinyi@tajiri.co.tz',
                'profile' => [
                    'first_name' => 'Baraka', 'last_name' => 'Mwinyi', 'username' => 'baraka_mwinyi',
                    'date_of_birth' => '1995-03-15', 'gender' => 'male',
                    'phone_number' => '+255712000001', 'is_phone_verified' => true,
                    'bio' => 'Software developer & tech enthusiast from Dar. Building the future one line at a time.',
                    'interests' => json_encode(['technology', 'coding', 'football', 'music']),
                    'relationship_status' => 'single',
                    'region_id' => 2, 'region_name' => 'Dar-es-salaam',
                    'district_id' => 11, 'district_name' => 'Kinondoni',
                    'ward_id' => 164, 'ward_name' => 'Bonyokwa',
                    'primary_school_id' => 795, 'primary_school_code' => 'PS0202112', 'primary_school_name' => 'ABC CAPITAL PRIMARY SCHOOL', 'primary_school_type' => 'government', 'primary_graduation_year' => 2008,
                    'secondary_school_id' => 1, 'secondary_school_code' => 'S0101', 'secondary_school_name' => 'AZANIA', 'secondary_school_type' => 'government', 'secondary_graduation_year' => 2012,
                    'alevel_school_id' => 1, 'alevel_school_code' => 'S0101', 'alevel_school_name' => 'AZANIA', 'alevel_school_type' => 'government', 'alevel_graduation_year' => 2014,
                    'alevel_combination_code' => 'PCM', 'alevel_combination_name' => 'Physics, Chemistry, Mathematics',
                    'alevel_subjects' => json_encode(['Physics', 'Chemistry', 'Mathematics']),
                    'university_id' => 1, 'university_code' => 'PUB001', 'university_name' => 'University of Dar es Salaam',
                    'programme_id' => 90, 'programme_name' => 'BSc in Mathematics', 'degree_level' => 'BSC',
                    'university_graduation_year' => 2018, 'is_current_student' => false,
                    'employer_id' => 1, 'employer_code' => 'CRDB', 'employer_name' => 'CRDB Bank Plc',
                    'employer_sector' => 'banking', 'employer_ownership' => 'public_listed', 'is_custom_employer' => false,
                ],
            ],
            // 2. Female, Arusha, Sokoine graduate, agricultural officer
            [
                'name' => 'Neema Kimaro', 'email' => 'neema.kimaro@tajiri.co.tz',
                'profile' => [
                    'first_name' => 'Neema', 'last_name' => 'Kimaro', 'username' => 'neema_kimaro',
                    'date_of_birth' => '1997-07-22', 'gender' => 'female',
                    'phone_number' => '+255713000002', 'is_phone_verified' => true,
                    'bio' => 'Agricultural scientist passionate about food security in East Africa.',
                    'interests' => json_encode(['agriculture', 'environment', 'reading', 'cooking']),
                    'relationship_status' => 'single',
                    'region_id' => 1, 'region_name' => 'Arusha',
                    'district_id' => 2, 'district_name' => 'Arusha',
                    'primary_school_id' => 2, 'primary_school_code' => 'PS0101128', 'primary_school_name' => 'ARUSHA VICTORY PRIMARY SCHOOL', 'primary_school_type' => 'government', 'primary_graduation_year' => 2010,
                    'secondary_school_id' => 7, 'secondary_school_code' => 'S0110', 'secondary_school_name' => 'ILBORU', 'secondary_school_type' => 'government', 'secondary_graduation_year' => 2014,
                    'alevel_school_id' => 4, 'alevel_school_code' => 'S0105', 'alevel_school_name' => 'CHIDYA', 'alevel_school_type' => 'government', 'alevel_graduation_year' => 2016,
                    'alevel_combination_code' => 'CBA', 'alevel_combination_name' => 'Chemistry, Biology, Agriculture',
                    'alevel_subjects' => json_encode(['Chemistry', 'Biology', 'Agriculture']),
                    'university_id' => 2, 'university_code' => 'PUB002', 'university_name' => 'Sokoine University of Agriculture',
                    'programme_id' => 159, 'programme_name' => 'BSc in Crop Science', 'degree_level' => 'BSC',
                    'university_graduation_year' => 2020, 'is_current_student' => false,
                    'employer_name' => 'Ministry of Agriculture', 'employer_sector' => 'government',
                    'employer_ownership' => 'government', 'is_custom_employer' => true,
                ],
            ],
            // 3. Male, Dodoma, University of Dodoma current student
            [
                'name' => 'Juma Hassan', 'email' => 'juma.hassan@tajiri.co.tz',
                'profile' => [
                    'first_name' => 'Juma', 'last_name' => 'Hassan', 'username' => 'juma_hassan',
                    'date_of_birth' => '2001-01-10', 'gender' => 'male',
                    'phone_number' => '+255754000003', 'is_phone_verified' => true,
                    'bio' => 'History student at UDOM. Love debating, writing and African culture.',
                    'interests' => json_encode(['history', 'debate', 'writing', 'politics']),
                    'relationship_status' => 'single',
                    'region_id' => 3, 'region_name' => 'Dodoma',
                    'district_id' => null, 'district_name' => null,
                    'primary_school_id' => null, 'primary_school_code' => null, 'primary_school_name' => null, 'primary_school_type' => null, 'primary_graduation_year' => 2014,
                    'secondary_school_id' => 5, 'secondary_school_code' => 'S0107', 'secondary_school_name' => 'LUTHERAN JUNIOR SEMINARY', 'secondary_school_type' => 'private', 'secondary_graduation_year' => 2018,
                    'alevel_school_id' => 2, 'alevel_school_code' => 'S0103', 'alevel_school_name' => 'BIHAWANA', 'alevel_school_type' => 'government', 'alevel_graduation_year' => 2020,
                    'alevel_combination_code' => 'HGL', 'alevel_combination_name' => 'History, Geography, Language',
                    'alevel_subjects' => json_encode(['History', 'Geography', 'Kiswahili']),
                    'university_id' => 4, 'university_code' => 'PUB004', 'university_name' => 'University of Dodoma',
                    'programme_id' => 266, 'programme_name' => 'BA in History', 'degree_level' => 'BSC',
                    'university_graduation_year' => 2026, 'is_current_student' => true,
                ],
            ],
            // 4. Female, DSM, Muhimbili medical doctor
            [
                'name' => 'Rehema Mchome', 'email' => 'rehema.mchome@tajiri.co.tz',
                'profile' => [
                    'first_name' => 'Rehema', 'last_name' => 'Mchome', 'username' => 'dr_rehema',
                    'date_of_birth' => '1993-11-05', 'gender' => 'female',
                    'phone_number' => '+255765000004', 'is_phone_verified' => true,
                    'bio' => 'Medical doctor at Muhimbili. Saving lives, one patient at a time.',
                    'interests' => json_encode(['medicine', 'health', 'fitness', 'travel']),
                    'relationship_status' => 'married',
                    'region_id' => 2, 'region_name' => 'Dar-es-salaam',
                    'district_id' => 8, 'district_name' => 'Ilala',
                    'ward_id' => 169, 'ward_name' => 'Ilala',
                    'primary_school_id' => 797, 'primary_school_code' => 'PS0202097', 'primary_school_name' => 'AFRICAN PRIMARY SCHOOL', 'primary_school_type' => 'government', 'primary_graduation_year' => 2006,
                    'secondary_school_id' => 3, 'secondary_school_code' => 'S0105', 'secondary_school_name' => 'CHIDYA', 'secondary_school_type' => 'government', 'secondary_graduation_year' => 2010,
                    'alevel_school_id' => 4, 'alevel_school_code' => 'S0105', 'alevel_school_name' => 'CHIDYA', 'alevel_school_type' => 'government', 'alevel_graduation_year' => 2012,
                    'alevel_combination_code' => 'PCB', 'alevel_combination_name' => 'Physics, Chemistry, Biology',
                    'alevel_subjects' => json_encode(['Physics', 'Chemistry', 'Biology']),
                    'university_id' => 5, 'university_code' => 'PUB005', 'university_name' => 'Muhimbili University of Health and Allied Sciences',
                    'programme_id' => 291, 'programme_name' => 'Doctor of Medicine', 'degree_level' => 'MD',
                    'university_graduation_year' => 2019, 'is_current_student' => false,
                    'employer_name' => 'Muhimbili National Hospital', 'employer_sector' => 'healthcare',
                    'employer_ownership' => 'government', 'is_custom_employer' => true,
                ],
            ],
            // 5. Male, Mbeya, VETA graduate, mechanic
            [
                'name' => 'Dickson Mwakyusa', 'email' => 'dickson.mwakyusa@tajiri.co.tz',
                'profile' => [
                    'first_name' => 'Dickson', 'last_name' => 'Mwakyusa', 'username' => 'dickson_mwaky',
                    'date_of_birth' => '1998-04-18', 'gender' => 'male',
                    'phone_number' => '+255716000005', 'is_phone_verified' => true,
                    'bio' => 'Certified mechanic & VETA alumni. Fixing engines and building dreams.',
                    'interests' => json_encode(['cars', 'mechanics', 'football', 'bongo_flava']),
                    'relationship_status' => 'in_relationship',
                    'region_id' => 22, 'region_name' => 'Mbeya',
                    'postsecondary_id' => 5, 'postsecondary_code' => 'VETA-MBEYA', 'postsecondary_name' => 'Mbeya Regional Vocational Training and Service Centre',
                    'postsecondary_type' => 'government', 'postsecondary_graduation_year' => 2018,
                    'employer_name' => 'Mwakyusa Auto Garage', 'employer_sector' => 'automotive',
                    'employer_ownership' => 'private', 'is_custom_employer' => true,
                ],
            ],
            // 6. Female, Kilimanjaro, NM-AIST postgrad student
            [
                'name' => 'Grace Mselle', 'email' => 'grace.mselle@tajiri.co.tz',
                'profile' => [
                    'first_name' => 'Grace', 'last_name' => 'Mselle', 'username' => 'grace_mselle',
                    'date_of_birth' => '1996-09-30', 'gender' => 'female',
                    'phone_number' => '+255787000006', 'is_phone_verified' => true,
                    'bio' => 'MSc student at NM-AIST. Researching renewable energy solutions for rural Tanzania.',
                    'interests' => json_encode(['science', 'renewable_energy', 'hiking', 'photography']),
                    'relationship_status' => 'single',
                    'region_id' => 1, 'region_name' => 'Arusha',
                    'district_id' => 2, 'district_name' => 'Arusha',
                    'primary_school_id' => 4, 'primary_school_code' => 'PS0101001', 'primary_school_name' => 'BANGATA PRIMARY SCHOOL', 'primary_school_type' => 'government', 'primary_graduation_year' => 2009,
                    'secondary_school_id' => 7, 'secondary_school_code' => 'S0110', 'secondary_school_name' => 'ILBORU', 'secondary_school_type' => 'government', 'secondary_graduation_year' => 2013,
                    'alevel_school_id' => 1, 'alevel_school_code' => 'S0101', 'alevel_school_name' => 'AZANIA', 'alevel_school_type' => 'government', 'alevel_graduation_year' => 2015,
                    'alevel_combination_code' => 'PCM', 'alevel_combination_name' => 'Physics, Chemistry, Mathematics',
                    'alevel_subjects' => json_encode(['Physics', 'Chemistry', 'Mathematics']),
                    'university_id' => 9, 'university_code' => 'PUB009', 'university_name' => 'Nelson Mandela African Institution of Science and Technology',
                    'programme_id' => null, 'programme_name' => 'MSc in Energy Engineering', 'degree_level' => 'MSC',
                    'university_graduation_year' => 2026, 'is_current_student' => true,
                ],
            ],
            // 7. Male, DSM, NMB Bank, Ardhi graduate (architecture)
            [
                'name' => 'Emmanuel Tarimo', 'email' => 'emmanuel.tarimo@tajiri.co.tz',
                'profile' => [
                    'first_name' => 'Emmanuel', 'last_name' => 'Tarimo', 'username' => 'emmy_tarimo',
                    'date_of_birth' => '1994-06-12', 'gender' => 'male',
                    'phone_number' => '+255718000007', 'is_phone_verified' => true,
                    'bio' => 'Architect & urban planner. Designing sustainable spaces for growing cities.',
                    'interests' => json_encode(['architecture', 'design', 'art', 'basketball']),
                    'relationship_status' => 'engaged',
                    'region_id' => 2, 'region_name' => 'Dar-es-salaam',
                    'district_id' => 13, 'district_name' => 'Ubungo',
                    'primary_school_id' => 798, 'primary_school_code' => 'PS0202100', 'primary_school_name' => 'AGNES MICHAEL PRIMARY SCHOOL', 'primary_school_type' => 'government', 'primary_graduation_year' => 2007,
                    'secondary_school_id' => 6, 'secondary_school_code' => 'S0108', 'secondary_school_name' => 'IFUNDA TECHNICAL', 'secondary_school_type' => 'government', 'secondary_graduation_year' => 2011,
                    'alevel_school_id' => 3, 'alevel_school_code' => 'S0104', 'alevel_school_name' => 'BWIRU BOYS\'', 'alevel_school_type' => 'government', 'alevel_graduation_year' => 2013,
                    'alevel_combination_code' => 'PGM', 'alevel_combination_name' => 'Physics, Geography, Mathematics',
                    'alevel_subjects' => json_encode(['Physics', 'Geography', 'Mathematics']),
                    'university_id' => 6, 'university_code' => 'PUB006', 'university_name' => 'Ardhi University',
                    'programme_id' => 330, 'programme_name' => 'Bachelor of Architecture', 'degree_level' => 'BENG',
                    'university_graduation_year' => 2018, 'is_current_student' => false,
                    'employer_id' => 2, 'employer_code' => 'NMB', 'employer_name' => 'NMB Bank Plc',
                    'employer_sector' => 'banking', 'employer_ownership' => 'public_listed', 'is_custom_employer' => false,
                ],
            ],
            // 8. Female, Morogoro, Mzumbe graduate, accountant
            [
                'name' => 'Fatma Salum', 'email' => 'fatma.salum@tajiri.co.tz',
                'profile' => [
                    'first_name' => 'Fatma', 'last_name' => 'Salum', 'username' => 'fatma_salum',
                    'date_of_birth' => '1996-02-28', 'gender' => 'female',
                    'phone_number' => '+255769000008', 'is_phone_verified' => true,
                    'bio' => 'Certified accountant. Numbers are my language.',
                    'interests' => json_encode(['finance', 'accounting', 'fashion', 'cooking']),
                    'relationship_status' => 'married',
                    'region_id' => 24, 'region_name' => 'Morogoro',
                    'university_id' => 7, 'university_code' => 'PUB007', 'university_name' => 'Mzumbe University',
                    'programme_id' => null, 'programme_name' => 'Bachelor of Commerce in Accounting', 'degree_level' => 'BSC',
                    'university_graduation_year' => 2019, 'is_current_student' => false,
                    'employer_id' => 8, 'employer_code' => 'DSE', 'employer_name' => 'Dar es Salaam Stock Exchange Plc',
                    'employer_sector' => 'financial_services', 'employer_ownership' => 'public_listed', 'is_custom_employer' => false,
                ],
            ],
            // 9. Male, Tanga, fisherman, secondary education only
            [
                'name' => 'Hamisi Bakari', 'email' => 'hamisi.bakari@tajiri.co.tz',
                'profile' => [
                    'first_name' => 'Hamisi', 'last_name' => 'Bakari', 'username' => 'hamisi_bakari',
                    'date_of_birth' => '1990-12-03', 'gender' => 'male',
                    'phone_number' => '+255752000009', 'is_phone_verified' => true,
                    'bio' => 'Fisherman from Tanga coast. The ocean is my office.',
                    'interests' => json_encode(['fishing', 'ocean', 'football', 'darts']),
                    'relationship_status' => 'married',
                    'region_id' => 26, 'region_name' => 'Tanga',
                    'secondary_school_id' => 9, 'secondary_school_code' => 'S0112', 'secondary_school_name' => 'IYUNGA TECHNICAL', 'secondary_school_type' => 'government', 'secondary_graduation_year' => 2008,
                    'employer_name' => 'Self-employed Fisherman', 'employer_sector' => 'fisheries',
                    'employer_ownership' => 'private', 'is_custom_employer' => true,
                ],
            ],
            // 10. Female, DSM, content creator, Open University
            [
                'name' => 'Halima Mwanga', 'email' => 'halima.mwanga@tajiri.co.tz',
                'profile' => [
                    'first_name' => 'Halima', 'last_name' => 'Mwanga', 'username' => 'halima_creator',
                    'date_of_birth' => '1999-08-14', 'gender' => 'female',
                    'phone_number' => '+255784000010', 'is_phone_verified' => true,
                    'bio' => 'Digital content creator & influencer. Sharing Tanzanian culture with the world.',
                    'interests' => json_encode(['content_creation', 'fashion', 'travel', 'photography']),
                    'relationship_status' => 'single',
                    'region_id' => 2, 'region_name' => 'Dar-es-salaam',
                    'district_id' => 11, 'district_name' => 'Kinondoni',
                    'university_id' => 3, 'university_code' => 'PUB003', 'university_name' => 'Open University of Tanzania',
                    'programme_id' => 213, 'programme_name' => 'BCom in Finance', 'degree_level' => 'BSC',
                    'university_graduation_year' => 2023, 'is_current_student' => false,
                    'employer_name' => 'Halima Media', 'employer_sector' => 'media',
                    'employer_ownership' => 'private', 'is_custom_employer' => true,
                ],
            ],
            // 11. Male, Mwanza, musician, no university
            [
                'name' => 'Kelvin Magesa', 'email' => 'kelvin.magesa@tajiri.co.tz',
                'profile' => [
                    'first_name' => 'Kelvin', 'last_name' => 'Magesa', 'username' => 'kelvin_beats',
                    'date_of_birth' => '2000-05-20', 'gender' => 'male',
                    'phone_number' => '+255711000011', 'is_phone_verified' => true,
                    'bio' => 'Bongo Flava artist & producer from Mwanza. Music is life.',
                    'interests' => json_encode(['music', 'bongo_flava', 'studio', 'dance']),
                    'relationship_status' => 'in_relationship',
                    'region_id' => 18, 'region_name' => 'Mwanza',
                    'secondary_school_id' => 3, 'secondary_school_code' => 'S0105', 'secondary_school_name' => 'CHIDYA', 'secondary_school_type' => 'government', 'secondary_graduation_year' => 2018,
                    'employer_name' => 'Kelvin Beats Studio', 'employer_sector' => 'entertainment',
                    'employer_ownership' => 'private', 'is_custom_employer' => true,
                ],
            ],
            // 12. Female, Iringa, teacher, UDOM graduate
            [
                'name' => 'Zawadi Lupembe', 'email' => 'zawadi.lupembe@tajiri.co.tz',
                'profile' => [
                    'first_name' => 'Zawadi', 'last_name' => 'Lupembe', 'username' => 'zawadi_teacher',
                    'date_of_birth' => '1992-10-25', 'gender' => 'female',
                    'phone_number' => '+255763000012', 'is_phone_verified' => true,
                    'bio' => 'Secondary school teacher in Iringa. Education changes everything.',
                    'interests' => json_encode(['education', 'reading', 'gardening', 'church']),
                    'relationship_status' => 'married',
                    'region_id' => 5, 'region_name' => 'Iringa',
                    'secondary_school_id' => 6, 'secondary_school_code' => 'S0108', 'secondary_school_name' => 'IFUNDA TECHNICAL', 'secondary_school_type' => 'government', 'secondary_graduation_year' => 2010,
                    'alevel_school_id' => 2, 'alevel_school_code' => 'S0103', 'alevel_school_name' => 'BIHAWANA', 'alevel_school_type' => 'government', 'alevel_graduation_year' => 2012,
                    'alevel_combination_code' => 'CBG', 'alevel_combination_name' => 'Chemistry, Biology, Geography',
                    'alevel_subjects' => json_encode(['Chemistry', 'Biology', 'Geography']),
                    'university_id' => 4, 'university_code' => 'PUB004', 'university_name' => 'University of Dodoma',
                    'programme_id' => 268, 'programme_name' => 'BA in Sociology', 'degree_level' => 'BSC',
                    'university_graduation_year' => 2016, 'is_current_student' => false,
                    'employer_name' => 'Iringa Girls Secondary School', 'employer_sector' => 'education',
                    'employer_ownership' => 'government', 'is_custom_employer' => true,
                ],
            ],
            // 13. Male, DSM, private uni (Hubert Kairuki), doctor
            [
                'name' => 'Peter Mushi', 'email' => 'peter.mushi@tajiri.co.tz',
                'profile' => [
                    'first_name' => 'Peter', 'last_name' => 'Mushi', 'username' => 'dr_mushi',
                    'date_of_birth' => '1991-04-08', 'gender' => 'male',
                    'phone_number' => '+255756000013', 'is_phone_verified' => true,
                    'bio' => 'Doctor at Aga Khan Hospital. Passionate about community health.',
                    'interests' => json_encode(['medicine', 'community_health', 'running', 'chess']),
                    'relationship_status' => 'married',
                    'region_id' => 2, 'region_name' => 'Dar-es-salaam',
                    'district_id' => 8, 'district_name' => 'Ilala',
                    'primary_school_id' => 799, 'primary_school_code' => 'PS0202052', 'primary_school_name' => 'AIR WING PRIMARY SCHOOL', 'primary_school_type' => 'government', 'primary_graduation_year' => 2004,
                    'secondary_school_id' => 1, 'secondary_school_code' => 'S0101', 'secondary_school_name' => 'AZANIA', 'secondary_school_type' => 'government', 'secondary_graduation_year' => 2008,
                    'alevel_school_id' => 1, 'alevel_school_code' => 'S0101', 'alevel_school_name' => 'AZANIA', 'alevel_school_type' => 'government', 'alevel_graduation_year' => 2010,
                    'alevel_combination_code' => 'PCB', 'alevel_combination_name' => 'Physics, Chemistry, Biology',
                    'alevel_subjects' => json_encode(['Physics', 'Chemistry', 'Biology']),
                    'university_id' => 14, 'university_code' => 'PRV001', 'university_name' => 'Hubert Kairuki Memorial University',
                    'programme_id' => 451, 'programme_name' => 'Doctor of Medicine', 'degree_level' => 'MD',
                    'university_graduation_year' => 2017, 'is_current_student' => false,
                    'employer_name' => 'Aga Khan Hospital', 'employer_sector' => 'healthcare',
                    'employer_ownership' => 'private', 'is_custom_employer' => true,
                ],
            ],
            // 14. Female, Kagera, nurse, St. Augustine graduate
            [
                'name' => 'Mariam Rwegasira', 'email' => 'mariam.rwegasira@tajiri.co.tz',
                'profile' => [
                    'first_name' => 'Mariam', 'last_name' => 'Rwegasira', 'username' => 'mariam_rwe',
                    'date_of_birth' => '1997-01-17', 'gender' => 'female',
                    'phone_number' => '+255789000014', 'is_phone_verified' => true,
                    'bio' => 'Nurse from Bukoba. Healthcare hero serving rural communities.',
                    'interests' => json_encode(['nursing', 'volunteering', 'music', 'swimming']),
                    'relationship_status' => 'single',
                    'region_id' => 6, 'region_name' => 'Kagera',
                    'university_id' => 15, 'university_code' => 'PRV002', 'university_name' => 'St. Augustine University of Tanzania',
                    'programme_id' => null, 'programme_name' => 'BSc in Nursing', 'degree_level' => 'BSC',
                    'university_graduation_year' => 2021, 'is_current_student' => false,
                    'employer_name' => 'Bukoba Regional Hospital', 'employer_sector' => 'healthcare',
                    'employer_ownership' => 'government', 'is_custom_employer' => true,
                ],
            ],
            // 15. Male, DSM, VETA DSM, electrician
            [
                'name' => 'Salim Mwambingu', 'email' => 'salim.mwambingu@tajiri.co.tz',
                'profile' => [
                    'first_name' => 'Salim', 'last_name' => 'Mwambingu', 'username' => 'salim_electric',
                    'date_of_birth' => '1995-06-09', 'gender' => 'male',
                    'phone_number' => '+255717000015', 'is_phone_verified' => true,
                    'bio' => 'Licensed electrician. Keeping Dar es Salaam powered up!',
                    'interests' => json_encode(['electrical', 'technology', 'football', 'barbecue']),
                    'relationship_status' => 'married',
                    'region_id' => 2, 'region_name' => 'Dar-es-salaam',
                    'district_id' => 12, 'district_name' => 'Temeke',
                    'primary_school_id' => 800, 'primary_school_code' => 'PS0202261', 'primary_school_name' => 'AL-BAYAAN PRIMARY SCHOOL', 'primary_school_type' => 'government', 'primary_graduation_year' => 2008,
                    'secondary_school_id' => 9, 'secondary_school_code' => 'S0112', 'secondary_school_name' => 'IYUNGA TECHNICAL', 'secondary_school_type' => 'government', 'secondary_graduation_year' => 2012,
                    'postsecondary_id' => 1, 'postsecondary_code' => 'VETA-DSM', 'postsecondary_name' => 'Dar es Salaam Regional Vocational Training and Service Centre',
                    'postsecondary_type' => 'government', 'postsecondary_graduation_year' => 2014,
                    'employer_name' => 'TANESCO', 'employer_sector' => 'energy',
                    'employer_ownership' => 'government', 'is_custom_employer' => true,
                ],
            ],
            // 16. Female, Moshi/Kilimanjaro, MoCU graduate, cooperative officer
            [
                'name' => 'Anna Lyimo', 'email' => 'anna.lyimo@tajiri.co.tz',
                'profile' => [
                    'first_name' => 'Anna', 'last_name' => 'Lyimo', 'username' => 'anna_lyimo',
                    'date_of_birth' => '1994-03-22', 'gender' => 'female',
                    'phone_number' => '+255783000016', 'is_phone_verified' => true,
                    'bio' => 'Cooperative development officer. Empowering communities through SACCOS.',
                    'interests' => json_encode(['cooperatives', 'microfinance', 'women_empowerment', 'hiking']),
                    'relationship_status' => 'in_relationship',
                    'region_id' => 9, 'region_name' => 'Kilimanjaro',
                    'university_id' => 11, 'university_code' => 'PUB011', 'university_name' => 'Moshi Co-operative University',
                    'programme_id' => null, 'programme_name' => 'BA in Co-operative Management', 'degree_level' => 'BSC',
                    'university_graduation_year' => 2018, 'is_current_student' => false,
                    'employer_name' => 'KNCU - Kilimanjaro Native Cooperative Union', 'employer_sector' => 'cooperatives',
                    'employer_ownership' => 'private', 'is_custom_employer' => true,
                ],
            ],
            // 17. Male, DSM, UDSM engineering graduate, telecoms
            [
                'name' => 'Daniel Msuya', 'email' => 'daniel.msuya@tajiri.co.tz',
                'profile' => [
                    'first_name' => 'Daniel', 'last_name' => 'Msuya', 'username' => 'dan_msuya',
                    'date_of_birth' => '1993-07-04', 'gender' => 'male',
                    'phone_number' => '+255758000017', 'is_phone_verified' => true,
                    'bio' => 'Telecom engineer at Vodacom. Connecting Tanzania.',
                    'interests' => json_encode(['telecom', 'engineering', 'gaming', 'football']),
                    'relationship_status' => 'married',
                    'region_id' => 2, 'region_name' => 'Dar-es-salaam',
                    'district_id' => 13, 'district_name' => 'Ubungo',
                    'primary_school_id' => 801, 'primary_school_code' => 'PS0202111', 'primary_school_name' => 'AL-FAROUQ PRIMARY SCHOOL', 'primary_school_type' => 'government', 'primary_graduation_year' => 2006,
                    'secondary_school_id' => 1, 'secondary_school_code' => 'S0101', 'secondary_school_name' => 'AZANIA', 'secondary_school_type' => 'government', 'secondary_graduation_year' => 2010,
                    'alevel_school_id' => 1, 'alevel_school_code' => 'S0101', 'alevel_school_name' => 'AZANIA', 'alevel_school_type' => 'government', 'alevel_graduation_year' => 2012,
                    'alevel_combination_code' => 'PMC', 'alevel_combination_name' => 'Physics, Mathematics, Computer Science',
                    'alevel_subjects' => json_encode(['Physics', 'Mathematics', 'Computer Science']),
                    'university_id' => 1, 'university_code' => 'PUB001', 'university_name' => 'University of Dar es Salaam',
                    'programme_id' => 82, 'programme_name' => 'MSc in Telecommunications Engineering', 'degree_level' => 'MSC',
                    'university_graduation_year' => 2018, 'is_current_student' => false,
                    'employer_name' => 'Vodacom Tanzania', 'employer_sector' => 'telecommunications',
                    'employer_ownership' => 'private', 'is_custom_employer' => true,
                ],
            ],
            // 18. Female, Zanzibar/DSM, SUZA student
            [
                'name' => 'Aisha Omar', 'email' => 'aisha.omar@tajiri.co.tz',
                'profile' => [
                    'first_name' => 'Aisha', 'last_name' => 'Omar', 'username' => 'aisha_omar',
                    'date_of_birth' => '2002-11-19', 'gender' => 'female',
                    'phone_number' => '+255772000018', 'is_phone_verified' => true,
                    'bio' => 'Tourism student at SUZA. Exploring the beauty of Zanzibar and beyond.',
                    'interests' => json_encode(['tourism', 'travel', 'culture', 'spice_farming']),
                    'relationship_status' => 'single',
                    'region_id' => 2, 'region_name' => 'Dar-es-salaam',
                    'university_id' => 8, 'university_code' => 'PUB008', 'university_name' => 'State University of Zanzibar',
                    'programme_id' => null, 'programme_name' => 'BA in Tourism Management', 'degree_level' => 'BSC',
                    'university_graduation_year' => 2026, 'is_current_student' => true,
                ],
            ],
            // 19. Male, Tabora, farmer, primary school only
            [
                'name' => 'Rashidi Mnyamwezi', 'email' => 'rashidi.mnyamwezi@tajiri.co.tz',
                'profile' => [
                    'first_name' => 'Rashidi', 'last_name' => 'Mnyamwezi', 'username' => 'rashidi_farmer',
                    'date_of_birth' => '1985-02-14', 'gender' => 'male',
                    'phone_number' => '+255746000019', 'is_phone_verified' => true,
                    'bio' => 'Tobacco and honey farmer from Tabora. Living the rural life.',
                    'interests' => json_encode(['farming', 'beekeeping', 'traditional_music', 'nature']),
                    'relationship_status' => 'married',
                    'region_id' => 25, 'region_name' => 'Tabora',
                    'primary_graduation_year' => 1999,
                    'employer_name' => 'Self-employed Farmer', 'employer_sector' => 'agriculture',
                    'employer_ownership' => 'private', 'is_custom_employer' => true,
                ],
            ],
            // 20. Female, DSM, Mbeya Uni of Science graduate, lab technician
            [
                'name' => 'Salma Kileo', 'email' => 'salma.kileo@tajiri.co.tz',
                'profile' => [
                    'first_name' => 'Salma', 'last_name' => 'Kileo', 'username' => 'salma_kileo',
                    'date_of_birth' => '1998-12-01', 'gender' => 'female',
                    'phone_number' => '+255791000020', 'is_phone_verified' => true,
                    'bio' => 'Lab technician & science lover. Precision is everything.',
                    'interests' => json_encode(['science', 'lab_work', 'yoga', 'movies']),
                    'relationship_status' => 'single',
                    'region_id' => 2, 'region_name' => 'Dar-es-salaam',
                    'district_id' => 10, 'district_name' => 'Kigamboni',
                    'primary_school_id' => 802, 'primary_school_code' => 'PS0202068', 'primary_school_name' => 'AL-FURQAAN PRIMARY SCHOOL', 'primary_school_type' => 'government', 'primary_graduation_year' => 2011,
                    'secondary_school_id' => 3, 'secondary_school_code' => 'S0105', 'secondary_school_name' => 'CHIDYA', 'secondary_school_type' => 'government', 'secondary_graduation_year' => 2015,
                    'alevel_school_id' => 4, 'alevel_school_code' => 'S0105', 'alevel_school_name' => 'CHIDYA', 'alevel_school_type' => 'government', 'alevel_graduation_year' => 2017,
                    'alevel_combination_code' => 'CBG', 'alevel_combination_name' => 'Chemistry, Biology, Geography',
                    'alevel_subjects' => json_encode(['Chemistry', 'Biology', 'Geography']),
                    'university_id' => 10, 'university_code' => 'PUB010', 'university_name' => 'Mbeya University of Science and Technology',
                    'programme_id' => 397, 'programme_name' => 'BSc in Crop Science', 'degree_level' => 'BSC',
                    'university_graduation_year' => 2021, 'is_current_student' => false,
                    'employer_name' => 'Tanzania Bureau of Standards', 'employer_sector' => 'government',
                    'employer_ownership' => 'government', 'is_custom_employer' => true,
                ],
            ],
        ];

        DB::transaction(function () use ($users, $password, $now) {
            foreach ($users as $userData) {
                // Create auth user
                $user = User::create([
                    'name' => $userData['name'],
                    'email' => $userData['email'],
                    'password' => $password,
                    'email_verified_at' => $now,
                ]);

                // Create user profile
                $profileData = $userData['profile'];
                $profileData['created_at'] = $now;
                $profileData['updated_at'] = $now;

                UserProfile::create($profileData);

                // Create wallet
                DB::table('wallets')->insert([
                    'user_id' => UserProfile::where('phone_number', $profileData['phone_number'])->first()->id,
                    'balance' => rand(5000, 500000) / 100 * 100, // Random balance 5k-500k TZS
                    'pending_balance' => 0,
                    'currency' => 'TZS',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                // Create user presence
                DB::table('user_presence')->insert([
                    'user_id' => UserProfile::where('phone_number', $profileData['phone_number'])->first()->id,
                    'is_online' => rand(0, 1),
                    'last_seen_at' => $now->copy()->subMinutes(rand(0, 1440)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Create some friendships between users (profile IDs start after existing ones)
            $profileIds = UserProfile::orderBy('id', 'desc')->limit(20)->pluck('id')->toArray();
            if (count($profileIds) >= 20) {
                $friendships = [
                    [$profileIds[0], $profileIds[1]], // Baraka & Neema
                    [$profileIds[0], $profileIds[6]], // Baraka & Emmanuel
                    [$profileIds[0], $profileIds[16]], // Baraka & Daniel
                    [$profileIds[3], $profileIds[12]], // Rehema & Peter (doctors)
                    [$profileIds[3], $profileIds[13]], // Rehema & Mariam (healthcare)
                    [$profileIds[5], $profileIds[1]], // Grace & Neema (science women)
                    [$profileIds[2], $profileIds[11]], // Juma & Zawadi (UDOM)
                    [$profileIds[7], $profileIds[6]], // Fatma & Emmanuel (finance/arch)
                    [$profileIds[9], $profileIds[17]], // Halima & Aisha (young women DSM)
                    [$profileIds[10], $profileIds[4]], // Kelvin & Dickson (non-uni friends)
                    [$profileIds[14], $profileIds[16]], // Salim & Daniel (DSM guys)
                    [$profileIds[15], $profileIds[5]], // Anna & Grace (Kili/Arusha)
                ];

                foreach ($friendships as $pair) {
                    DB::table('friendships')->insert([
                        'user_id' => $pair[0],
                        'friend_id' => $pair[1],
                        'status' => 'accepted',
                        'accepted_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                // Update friend counts
                foreach ($profileIds as $pid) {
                    $count = DB::table('friendships')
                        ->where(function ($q) use ($pid) {
                            $q->where('user_id', $pid)->orWhere('friend_id', $pid);
                        })
                        ->where('status', 'accepted')
                        ->count();
                    if ($count > 0) {
                        DB::table('user_profiles')->where('id', $pid)->update(['friends_count' => $count]);
                    }
                }
            }
        });
    }
}
