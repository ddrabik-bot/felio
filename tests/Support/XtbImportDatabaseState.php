<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class XtbImportDatabaseState
{
    private const TABLES = [
        'portfolio_import_recalculation_boundaries',
        'portfolio_positions',
        'portfolio_import_source_rows',
        'portfolio_import_batches',
        'portfolio_accounts',
    ];

    public function prepare(): void
    {
        $this->truncate();
    }

    public function cleanup(): void
    {
        $this->truncate();
    }

    public function seedAccount(string $accountReference): int
    {
        if (trim($accountReference) === '') {
            throw new InvalidArgumentException('An XTB test account reference is required.');
        }

        return DB::table('portfolio_accounts')->insertGetId([
            'broker' => 'XTB',
            'account_reference' => $accountReference,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function truncate(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('XTB import database state requires PostgreSQL.');
        }

        DB::statement('TRUNCATE TABLE '.implode(', ', self::TABLES).' RESTART IDENTITY CASCADE');
    }
}
