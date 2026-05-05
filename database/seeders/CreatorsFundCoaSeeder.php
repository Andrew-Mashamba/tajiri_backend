<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds Creators Fund COA codes into the existing `coa_definitions` table.
 * Codes are in the 27xx / 47xx / 57xx ranges to avoid collision with the
 * existing accounting plan (where 2110-2140 are savings/deposits, 4110-4130
 * are loan fees, 5110-5134 are payroll lines).
 *
 * Strategy §12.2 — Creators Fund accounts.
 */
class CreatorsFundCoaSeeder extends Seeder
{
    /** Single source of truth for Creators Fund COA codes. */
    public const ACCOUNTS = [
        // Liabilities (major_code 2000)
        ['code' => '2705', 'name' => 'Creators Fund — Pending Distribution', 'major_code' => '2000'],
        ['code' => '2710', 'name' => 'Pending Creator Earnings — Engagement', 'major_code' => '2000'],
        ['code' => '2711', 'name' => 'Pending Creator Earnings — Fan-Funding', 'major_code' => '2000'],
        ['code' => '2712', 'name' => 'Pending Creator Earnings — Marketplace', 'major_code' => '2000'],
        ['code' => '2713', 'name' => 'Pending Creator Earnings — Brand-Deal', 'major_code' => '2000'],
        ['code' => '2714', 'name' => 'Pending Creator Earnings — Live-Gifts', 'major_code' => '2000'],
        ['code' => '2720', 'name' => 'Cleared Creator Earnings (Payable)', 'major_code' => '2000'],
        ['code' => '2730', 'name' => 'Creator Earnings Reserve Fund', 'major_code' => '2000'],
        ['code' => '2740', 'name' => 'TRA WHT Payable (Section 83B)', 'major_code' => '2000'],
        // Revenue (major_code 4000)
        ['code' => '4715', 'name' => 'Ad Revenue (Phase 2)', 'major_code' => '4000'],
        ['code' => '4720', 'name' => 'Platform Revenue — Fan-Funding Take', 'major_code' => '4000'],
        ['code' => '4730', 'name' => 'Platform Revenue — Marketplace Take', 'major_code' => '4000'],
        ['code' => '4740', 'name' => 'Platform Revenue — Brand-Deal Take', 'major_code' => '4000'],
        ['code' => '4750', 'name' => 'Platform Revenue — Live-Gifts Take', 'major_code' => '4000'],
        // Expense (major_code 5000)
        ['code' => '5710', 'name' => 'Creators Fund Outflow — Phase 1 CAC', 'major_code' => '5000'],
        ['code' => '5711', 'name' => 'Creators Fund Outflow — Phase 2 Rev-Share', 'major_code' => '5000'],
    ];

    public function run(): void
    {
        if (!Schema::hasTable('coa_definitions')) {
            throw new \RuntimeException(
                'CreatorsFundCoaSeeder requires the coa_definitions table. '
                . 'Deploy the accounting module before running this seeder.'
            );
        }

        $now = now();
        foreach (self::ACCOUNTS as $a) {
            DB::table('coa_definitions')->updateOrInsert(
                ['code' => $a['code']],
                array_merge($a, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }
    }
}
