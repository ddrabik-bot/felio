<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

it('runs manual-import integration checks against PostgreSQL', function (): void {
    expect(DB::getDriverName())->toBe('pgsql');
});

it('prepares deterministic XTB import state and removes seeded records during cleanup', function (): void {
    DB::table('portfolio_accounts')->insert([
        'broker' => 'XTB',
        'account_reference' => 'STALE-ACCOUNT',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $state = prepareXtbImportDatabaseState();

    expect(DB::table('portfolio_accounts')->count())->toBe(0);

    $accountId = $state->seedAccount('XTB-TEST-ACCOUNT');

    expect($accountId)->toBe(1)
        ->and(DB::table('portfolio_accounts')->where('id', $accountId)->value('broker'))->toBe('XTB')
        ->and(DB::table('portfolio_accounts')->where('id', $accountId)->value('account_reference'))->toBe('XTB-TEST-ACCOUNT');

    $state->cleanup();

    expect(DB::table('portfolio_import_recalculation_boundaries')->count())->toBe(0)
        ->and(DB::table('portfolio_positions')->count())->toBe(0)
        ->and(DB::table('portfolio_import_source_rows')->count())->toBe(0)
        ->and(DB::table('portfolio_import_batches')->count())->toBe(0)
        ->and(DB::table('portfolio_accounts')->count())->toBe(0);
});

it('fails explicitly when XTB import state is requested outside PostgreSQL', function (): void {
    $databaseManager = app('db');
    DB::shouldReceive('getDriverName')->once()->andReturn('sqlite');

    try {
        expect(fn () => prepareXtbImportDatabaseState())
            ->toThrow(RuntimeException::class, 'requires PostgreSQL');
    } finally {
        DB::swap($databaseManager);
    }
});
