<?php

use App\Domain\Fx\FxRateAvailability;
use App\Domain\Fx\FxRatePersistenceService;
use App\Domain\Fx\FxRateResult;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

function fxRateResult(
    FxRateAvailability $availability = FxRateAvailability::Available,
    ?string $effectiveDate = '2026-08-01',
    ?string $plnPerUnit = '3.98765432109876543210',
    ?string $reason = null,
    string $currency = 'USD',
): FxRateResult {
    return new FxRateResult(
        currency: $currency,
        requestedDate: new DateTimeImmutable('2026-08-01 00:00:00+02:00'),
        effectiveDate: $effectiveDate === null ? null : new DateTimeImmutable("{$effectiveDate} 00:00:00+02:00"),
        availability: $availability,
        plnPerUnit: $plnPerUnit,
        reason: $reason,
        attempts: 2,
        retrievedAt: new DateTimeImmutable('2026-08-01 12:34:56+00:00'),
        apiEndpoint: 'https://api.nbp.pl/api/exchangerates/rates/A/USD/2026-08-01/2026-08-01/?format=json',
        table: 'A',
        tableNumber: '151/A/NBP/2026',
        sourceTimezone: 'Europe/Warsaw',
        providerImplementationVersion: 'nbp-table-a-v1',
        providerApiContract: 'unversioned',
    );
}

it('upserts an available FX rate and preserves its exact decimal source value', function (): void {
    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $service = app(FxRatePersistenceService::class);
    $result = fxRateResult();

    $service->persist($result);
    $service->persist($result);

    $observation = DB::table('fx_rate_snapshots')->first();

    expect(DB::table('fx_rate_snapshots')->count())->toBe(1)
        ->and($observation->currency)->toBe('USD')
        ->and($observation->requested_date)->toBe('2026-08-01')
        ->and($observation->effective_date)->toBe('2026-08-01')
        ->and($observation->availability)->toBe('available')
        ->and($observation->pln_per_unit)->toBe('3.98765432109876543210')
        ->and($observation->provider_response_metadata)->toBeJson()
        ->and(collect($queries)->contains(static fn (string $sql): bool => str_contains($sql, 'on conflict')))->toBeTrue();
});

it('keeps an exact available FX rate canonical when a stale observation is persisted later', function (): void {
    $service = app(FxRatePersistenceService::class);

    $service->persist(fxRateResult());
    $service->persist(fxRateResult(FxRateAvailability::Stale, '2026-07-31', '3.90000000000000000001', 'effective_date_before_as_of_date'));

    $observation = DB::table('fx_rate_snapshots')->sole();

    expect(DB::table('fx_rate_snapshots')->count())->toBe(1)
        ->and($observation->availability)->toBe('available')
        ->and($observation->effective_date)->toBe('2026-08-01')
        ->and((string) $observation->pln_per_unit)->toBe('3.98765432109876543210');
});

it('replaces less specific FX results when an exact available rate is persisted', function (): void {
    $service = app(FxRatePersistenceService::class);

    $service->persist(fxRateResult(FxRateAvailability::Stale, '2026-07-31', '3.90000000000000000001', 'effective_date_before_as_of_date'));
    $service->persist(fxRateResult(FxRateAvailability::Unavailable, null, null, 'not_found'));
    $service->persist(fxRateResult());

    $observation = DB::table('fx_rate_snapshots')->sole();

    expect($observation->availability)->toBe('available')
        ->and($observation->effective_date)->toBe('2026-08-01')
        ->and((string) $observation->pln_per_unit)->toBe('3.98765432109876543210')
        ->and($observation->reason)->toBeNull();
});

it('reconciles historical duplicate FX rows by retaining the exact available rate', function (): void {
    DB::statement('ALTER TABLE fx_rate_snapshots DROP CONSTRAINT fx_rate_snapshots_provider_currency_date_unique');
    DB::statement('ALTER TABLE fx_rate_snapshots ADD CONSTRAINT fx_rate_snapshots_source_observation_unique UNIQUE (provider_implementation_version, currency, requested_date, source_observation_identity)');

    $now = now();
    DB::table('fx_rate_snapshots')->insert([
        [
            'provider_implementation_version' => 'nbp-table-a-v1',
            'currency' => 'USD',
            'requested_date' => '2026-08-01',
            'source_observation_identity' => hash('sha256', 'available'),
            'effective_date' => '2026-08-01',
            'availability' => 'available',
            'pln_per_unit' => '3.98765432109876543210',
            'reason' => null,
            'attempts' => 1,
            'retrieved_at' => '2026-08-01 10:00:00+00:00',
            'api_endpoint' => 'https://example.test/fx',
            'table' => 'A',
            'source_timezone' => 'Europe/Warsaw',
            'provider_response_metadata' => json_encode(['table_number' => '151/A/NBP/2026'], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'provider_implementation_version' => 'nbp-table-a-v1',
            'currency' => 'USD',
            'requested_date' => '2026-08-01',
            'source_observation_identity' => hash('sha256', 'stale'),
            'effective_date' => '2026-07-31',
            'availability' => 'stale',
            'pln_per_unit' => '3.90000000000000000001',
            'reason' => 'effective_date_before_as_of_date',
            'attempts' => 1,
            'retrieved_at' => '2026-08-01 11:00:00+00:00',
            'api_endpoint' => 'https://example.test/fx',
            'table' => 'A',
            'source_timezone' => 'Europe/Warsaw',
            'provider_response_metadata' => json_encode(['table_number' => '150/A/NBP/2026'], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $migration = require base_path('database/migrations/2026_08_14_230000_canonicalize_fx_rate_snapshot_identity.php');
    $migration->up();

    $observation = DB::table('fx_rate_snapshots')->sole();

    expect($observation->availability)->toBe('available')
        ->and($observation->effective_date)->toBe('2026-08-01')
        ->and((string) $observation->pln_per_unit)->toBe('3.98765432109876543210');
});

it('keeps one logical FX observation when PostgreSQL workers import it concurrently', function (): void {
    expect(DB::getDriverName())->toBe('pgsql');

    $barrier = tempnam(sys_get_temp_dir(), 'felio-fx-rate-');
    unlink($barrier);
    $currency = 'X'.strtoupper(bin2hex(random_bytes(1)));
    $worker = base_path('tests/Fixtures/Fx/PersistFxRateWorker.php');
    $processes = array_map(
        static fn (): Process => new Process([PHP_BINARY, $worker, $currency, $barrier], base_path()),
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

        expect(DB::table('fx_rate_snapshots')->where('currency', $currency)->count())->toBe(1);
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
