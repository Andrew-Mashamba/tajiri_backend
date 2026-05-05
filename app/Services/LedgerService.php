<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Thin wrapper around the existing accounting module
 * (`accounting_books` / `coa_definitions` / `book_accounts` /
 * `journal_entries` / `journal_lines`). Encapsulates the
 * "find platform book, ensure book_account exists, write entry +
 * 2 lines, update balances" sequence so EarningsEngine doesn't
 * have to know the table layout.
 *
 * Strategy §5.1, §6, §7 — every cent moved by the Creators Fund
 * goes through this service and lands in the canonical ledger.
 */
class LedgerService
{
    /**
     * Post a balanced double-entry to the platform book.
     *
     * @param string $debitCode  COA code to debit (must exist in coa_definitions)
     * @param string $creditCode COA code to credit
     * @param float  $amount     positive amount (TZS)
     * @param string $sourceType e.g. 'earning_event', 'creators_fund_period', 'tra_remittance'
     * @param int    $sourceId   the FK id of the source row
     * @param string $description human-readable description (max 512 chars)
     * @return int journal_entries.id (caller may persist this on the source row)
     */
    public static function post(
        string $debitCode,
        string $creditCode,
        float $amount,
        string $sourceType,
        int $sourceId,
        string $description
    ): int {
        if (!Schema::hasTable('journal_entries')
            || !Schema::hasTable('journal_lines')
            || !Schema::hasTable('book_accounts')
            || !Schema::hasTable('coa_definitions')
            || !Schema::hasTable('accounting_books')) {
            throw new \RuntimeException('Accounting module not deployed.');
        }
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Ledger amount must be > 0');
        }

        return DB::transaction(function () use ($debitCode, $creditCode, $amount, $sourceType, $sourceId, $description) {
            $bookId = self::platformBookId();
            $debitAcct  = self::ensureBookAccount($bookId, $debitCode);
            $creditAcct = self::ensureBookAccount($bookId, $creditCode);

            // Allocate next entry_number for this book.
            $nextNumber = (int) (DB::table('journal_entries')
                ->where('book_id', $bookId)
                ->max('entry_number') ?? 0) + 1;

            $entryId = DB::table('journal_entries')->insertGetId([
                'book_id'      => $bookId,
                'entry_number' => $nextNumber,
                'source_type'  => substr($sourceType, 0, 120),
                'source_id'    => $sourceId,
                'description'  => substr($description, 0, 512),
                'metadata'     => json_encode([
                    'debit_code'  => $debitCode,
                    'credit_code' => $creditCode,
                ]),
                'posted_at'    => now(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            DB::table('journal_lines')->insert([
                ['journal_entry_id' => $entryId, 'book_account_id' => $debitAcct,  'debit'  => $amount, 'credit' => 0,       'created_at' => now(), 'updated_at' => now()],
                ['journal_entry_id' => $entryId, 'book_account_id' => $creditAcct, 'debit'  => 0,       'credit' => $amount, 'created_at' => now(), 'updated_at' => now()],
            ]);

            // Maintain book_accounts.balance for fast read.
            // Convention: debit increases assets/expenses, decreases liabilities/revenue.
            // We store running totals on book_accounts.balance — sign follows the line direction.
            DB::table('book_accounts')->where('id', $debitAcct)->increment('balance',  $amount);
            DB::table('book_accounts')->where('id', $creditAcct)->decrement('balance', $amount);

            return (int) $entryId;
        });
    }

    /** Find or create the platform `accounting_books` row. */
    public static function platformBookId(): int
    {
        $row = DB::table('accounting_books')->where('type', 'platform')->first();
        if ($row) {
            return (int) $row->id;
        }
        return (int) DB::table('accounting_books')->insertGetId([
            'type'       => 'platform',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Find or create the `book_accounts` row for (bookId, coaCode). */
    public static function ensureBookAccount(int $bookId, string $coaCode): int
    {
        $coa = DB::table('coa_definitions')->where('code', $coaCode)->first();
        if (!$coa) {
            throw new \RuntimeException("COA code '{$coaCode}' not found in coa_definitions. Run CreatorsFundCoaSeeder.");
        }
        $existing = DB::table('book_accounts')
            ->where('book_id', $bookId)
            ->where('coa_definition_id', $coa->id)
            ->first();
        if ($existing) {
            return (int) $existing->id;
        }
        return (int) DB::table('book_accounts')->insertGetId([
            'book_id'           => $bookId,
            'coa_definition_id' => $coa->id,
            'balance'           => 0,
            'currency'          => 'TZS',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }
}
