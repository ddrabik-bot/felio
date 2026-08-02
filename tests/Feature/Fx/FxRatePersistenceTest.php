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

it('keeps available, stale, and unavailable FX observations observable without a date fallback', function (): void {
    $service = app(FxRatePersistenceService::class);

    $service->persist(fxRateResult());
    $service->persist(fxRateResult(FxRateAvailability::Stale, '2026-07-31', '3.90000000000000000001', 'effective_date_before_as_of_date'));
    $service->persist(fxRateResult(FxRateAvailability::Unavailable, null, null, 'not_found'));

    expect(DB::table('fx_rate_snapshots')->orderBy('availability')->pluck('availability')->all())
        ->toBe(['available', 'stale', 'unavailable'])
        ->and(DB::table('fx_rate_snapshots')->where('availability', 'stale')->value('effective_date'))->toBe('2026-07-31')
        ->and(DB::table('fx_rate_snapshots')->where('availability', 'unavailable')->value('effective_date'))->toBeNull()
        ->and(DB::table('fx_rate_snapshots')->where('availability', 'unavailable')->value('pln_per_unit'))->toBeNull()
        ->and(DB::table('fx_rate_snapshots')->where('availability', 'unavailable')->value('reason'))->toBe('not_found');
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
