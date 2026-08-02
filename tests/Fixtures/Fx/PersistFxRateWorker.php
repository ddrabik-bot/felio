<?php

use App\Domain\Fx\FxRateAvailability;
use App\Domain\Fx\FxRatePersistenceService;
use App\Domain\Fx\FxRateResult;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$currency, $barrier] = array_slice($argv, 1);
$ready = sprintf('%s.%d.ready', $barrier, getmypid());
touch($ready);

while (! file_exists($barrier)) {
    usleep(10_000);
}

$app->make(FxRatePersistenceService::class)->persist(new FxRateResult(
    currency: $currency,
    requestedDate: new DateTimeImmutable('2026-08-01 00:00:00+02:00'),
    effectiveDate: new DateTimeImmutable('2026-08-01 00:00:00+02:00'),
    availability: FxRateAvailability::Available,
    plnPerUnit: '3.98765432109876543210',
    reason: null,
    attempts: 1,
    retrievedAt: new DateTimeImmutable('2026-08-01 12:34:56+00:00'),
    apiEndpoint: 'https://api.nbp.pl/api/exchangerates/rates/A/USD/2026-08-01/2026-08-01/?format=json',
    table: 'A',
    tableNumber: '151/A/NBP/2026',
    sourceTimezone: 'Europe/Warsaw',
    providerImplementationVersion: 'nbp-table-a-v1',
    providerApiContract: 'unversioned',
));
