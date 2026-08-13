<?php

use App\Domain\MarketData\CanonicalInstrument;
use App\Domain\Portfolio\ImportBatch;
use App\Domain\Portfolio\PortfolioImportRow;
use App\Domain\Portfolio\PortfolioImportService;
use App\Domain\Portfolio\SourceRowStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

function portfolioImportBatch(string $identity = 'xtb-statement-2026-08-01'): ImportBatch
{
    return new ImportBatch(
        broker: 'xtb',
        accountReference: 'XTB-PLN-001',
        sourceBatchIdentity: $identity,
        importedAt: new DateTimeImmutable('2026-08-01T12:34:56+00:00'),
    );
}

function validPortfolioImportRow(string $identity = 'row-0001'): PortfolioImportRow
{
    return PortfolioImportRow::valid(
        sourceRowIdentity: $identity,
        instrument: new CanonicalInstrument('PZU.PL'),
        quantity: '123.45000000000000000001',
        averageCostPlnGrosze: 6789,
        asOf: new DateTimeImmutable('2026-08-01T00:00:00+02:00'),
        rawValues: ['symbol' => 'PZU', 'quantity' => '123,45000000000000000001', 'average_cost' => '67,89'],
    );
}

it('derives source-row identities from raw values and a stable source reference', function (): void {
    $rawValues = ['symbol' => 'PZU', 'quantity' => '123.45000000000000000001'];

    expect(PortfolioImportRow::deterministicIdentity($rawValues, 'statement-row-1'))
        ->toBe(PortfolioImportRow::deterministicIdentity(['quantity' => '123.45000000000000000001', 'symbol' => 'PZU'], 'statement-row-1'))
        ->not->toBe(PortfolioImportRow::deterministicIdentity($rawValues, 'statement-row-2'));
});

it('reimports an identical batch idempotently and preserves exact position values', function (): void {
    $service = app(PortfolioImportService::class);
    $batch = portfolioImportBatch();
    $row = validPortfolioImportRow();

    $service->persist($batch, [$row]);
    $service->persist($batch, [$row]);

    $position = DB::table('portfolio_positions')->first();

    expect(DB::table('portfolio_accounts')->count())->toBe(1)
        ->and(DB::table('portfolio_import_batches')->count())->toBe(1)
        ->and(DB::table('portfolio_import_source_rows')->count())->toBe(1)
        ->and(DB::table('portfolio_positions')->count())->toBe(1)
        ->and($position->canonical_instrument)->toBe('PZU.PL')
        ->and($position->quantity)->toBe('123.45000000000000000001')
        ->and($position->average_cost_pln_grosze)->toBe(6789);
});

it('persists a rejected source row with diagnostics without preventing valid rows in the same batch', function (): void {
    app(PortfolioImportService::class)->persist(portfolioImportBatch(), [
        validPortfolioImportRow(),
        PortfolioImportRow::rejected(
            sourceRowIdentity: 'row-0002',
            asOf: new DateTimeImmutable('2026-08-01T00:00:00+02:00'),
            rawValues: ['symbol' => 'UNKNOWN', 'quantity' => 'not-a-number'],
            diagnostic: 'canonical_instrument_unresolved',
        ),
    ]);

    $rejected = DB::table('portfolio_import_source_rows')->where('status', SourceRowStatus::Rejected->value)->first();

    expect(DB::table('portfolio_import_source_rows')->count())->toBe(2)
        ->and(DB::table('portfolio_positions')->count())->toBe(1)
        ->and($rejected->canonical_instrument)->toBeNull()
        ->and($rejected->quantity)->toBeNull()
        ->and($rejected->diagnostic)->toBe('canonical_instrument_unresolved')
        ->and($rejected->raw_values)->toBeJson();
});

it('enforces one normalized position per account and canonical instrument', function (): void {
    $service = app(PortfolioImportService::class);
    $service->persist(portfolioImportBatch(), [validPortfolioImportRow()]);

    $position = DB::table('portfolio_positions')->first();

    expect(fn (): bool => DB::table('portfolio_positions')->insert([
        'portfolio_account_id' => $position->portfolio_account_id,
        'canonical_instrument' => $position->canonical_instrument,
        'quantity' => '1',
        'as_of' => '2026-08-01T00:00:00+02:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('aggregates ordered XTB trades into a single net position with an exact weighted average cost', function (): void {
    $rows = [];
    for ($index = 1; $index <= 22; $index++) {
        $quantity = $index <= 16 ? '2' : '1';
        $price = $index <= 16 ? 1000 : 2000;
        $rows[] = PortfolioImportRow::valid("xtb-order-{$index}", new CanonicalInstrument('XTB.PL'), $quantity, $price, new DateTimeImmutable(sprintf('2026-08-%02dT00:00:00+00:00', $index)), ['xtb_operation' => 'buy', 'xtb_price' => (string) bcdiv((string) $price, '100', 2)]);
    }

    app(PortfolioImportService::class)->persist(portfolioImportBatch('xtb-aggregate-22-rows'), $rows);

    $position = DB::table('portfolio_positions')->where('canonical_instrument', 'XTB.PL')->first();

    expect($position->quantity)->toBe('38')
        ->and($position->average_cost_pln_grosze)->toBe(1157);
});

it('applies XTB sells to the net quantity without changing the weighted average cost of remaining shares', function (): void {
    app(PortfolioImportService::class)->persist(portfolioImportBatch('xtb-buy-sell'), [
        PortfolioImportRow::valid('xtb-buy', new CanonicalInstrument('XTB.PL'), '10', 1000, new DateTimeImmutable('2026-08-01T00:00:00+00:00'), ['xtb_operation' => 'buy', 'xtb_price' => '10']),
        PortfolioImportRow::valid('xtb-sell', new CanonicalInstrument('XTB.PL'), '4', 9999, new DateTimeImmutable('2026-08-02T00:00:00+00:00'), ['xtb_operation' => 'sell', 'xtb_price' => '99.99']),
    ]);

    $position = DB::table('portfolio_positions')->where('canonical_instrument', 'XTB.PL')->first();

    expect($position->quantity)->toBe('6')
        ->and($position->average_cost_pln_grosze)->toBe(1000);
});

it('retains a fractional XTB weighted cost basis through a partial sell before a later buy', function (): void {
    app(PortfolioImportService::class)->persist(portfolioImportBatch('xtb-fractional-buy-sell-buy'), [
        PortfolioImportRow::valid('xtb-buy-one', new CanonicalInstrument('XTB.PL'), '0.1', 1000, new DateTimeImmutable('2026-08-01T00:00:00+00:00'), ['xtb_operation' => 'buy']),
        PortfolioImportRow::valid('xtb-buy-two', new CanonicalInstrument('XTB.PL'), '0.1', 1001, new DateTimeImmutable('2026-08-02T00:00:00+00:00'), ['xtb_operation' => 'buy']),
        PortfolioImportRow::valid('xtb-partial-sell', new CanonicalInstrument('XTB.PL'), '0.1', 9999, new DateTimeImmutable('2026-08-03T00:00:00+00:00'), ['xtb_operation' => 'sell']),
        PortfolioImportRow::valid('xtb-buy-three', new CanonicalInstrument('XTB.PL'), '0.1', 995, new DateTimeImmutable('2026-08-04T00:00:00+00:00'), ['xtb_operation' => 'buy']),
    ]);

    $position = DB::table('portfolio_positions')->where('canonical_instrument', 'XTB.PL')->first();

    expect($position->quantity)->toBe('0.2')
        ->and($position->average_cost_pln_grosze)->toBe(997);
});

it('keeps non-XTB source rows as latest-snapshot overwrites rather than net trade accumulation', function (): void {
    app(PortfolioImportService::class)->persist(portfolioImportBatch('generic-snapshots'), [
        PortfolioImportRow::valid('snapshot-one', new CanonicalInstrument('PZU.PL'), '2', 1000, new DateTimeImmutable('2026-08-01T00:00:00+00:00'), ['source' => 'generic']),
        PortfolioImportRow::valid('snapshot-two', new CanonicalInstrument('PZU.PL'), '5', 2000, new DateTimeImmutable('2026-08-02T00:00:00+00:00'), ['source' => 'generic']),
    ]);

    $position = DB::table('portfolio_positions')->where('canonical_instrument', 'PZU.PL')->first();

    expect($position->quantity)->toBe('5')
        ->and($position->average_cost_pln_grosze)->toBe(2000);
});

it('retains a non-XTB snapshot cost basis when a later XTB buy is aggregated', function (): void {
    app(PortfolioImportService::class)->persist(portfolioImportBatch('generic-snapshot-then-xtb-buy'), [
        PortfolioImportRow::valid('generic-snapshot', new CanonicalInstrument('PZU.PL'), '2', 1000, new DateTimeImmutable('2026-08-01T00:00:00+00:00'), ['source' => 'generic']),
        PortfolioImportRow::valid('xtb-buy', new CanonicalInstrument('PZU.PL'), '1', 2000, new DateTimeImmutable('2026-08-02T00:00:00+00:00'), ['xtb_operation' => 'buy', 'xtb_price' => '20']),
    ]);

    $position = DB::table('portfolio_positions')->where('canonical_instrument', 'PZU.PL')->first();

    expect($position->quantity)->toBe('3')
        ->and($position->average_cost_pln_grosze)->toBe(1333)
        ->and($position->as_of)->toBe('2026-08-02 00:00:00+00')
        ->and($position->source_import_batch_id)->toBe(DB::table('portfolio_import_batches')->where('source_batch_identity', 'generic-snapshot-then-xtb-buy')->value('id'));
});

it('keeps one source row and normalized position when PostgreSQL workers persist it concurrently', function (): void {
    expect(DB::getDriverName())->toBe('pgsql');

    $barrier = tempnam(sys_get_temp_dir(), 'felio-portfolio-import-');
    unlink($barrier);
    $batchIdentity = 'xtb-concurrent-'.bin2hex(random_bytes(4));
    $worker = base_path('tests/Fixtures/Portfolio/PersistPortfolioImportWorker.php');
    $processes = array_map(
        static fn (): Process => new Process([PHP_BINARY, $worker, $batchIdentity, $barrier], base_path()),
        range(1, 4),
    );

    try {
        foreach ($processes as $process) {
            $process->start();
        }

        $deadline = microtime(true) + 10;
        while (count(glob("{$barrier}.*.ready")) !== count($processes) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        expect(glob("{$barrier}.*.ready"))->toHaveCount(count($processes));
        touch($barrier);

        foreach ($processes as $process) {
            $process->wait();
            expect($process->getExitCode(), $process->getErrorOutput())->toBe(0);
        }

        expect(DB::table('portfolio_import_batches')->where('source_batch_identity', $batchIdentity)->count())->toBe(1)
            ->and(DB::table('portfolio_import_source_rows')->count())->toBe(1)
            ->and(DB::table('portfolio_positions')->count())->toBe(1);
    } finally {
        touch($barrier);

        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }

        foreach (glob("{$barrier}*") as $path) {
            unlink($path);
        }
    }
});
