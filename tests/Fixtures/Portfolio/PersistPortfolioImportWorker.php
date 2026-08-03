<?php

use App\Domain\MarketData\CanonicalInstrument;
use App\Domain\Portfolio\ImportBatch;
use App\Domain\Portfolio\PortfolioImportRow;
use App\Domain\Portfolio\PortfolioImportService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $batchIdentity, $barrier] = $argv;
$ready = "{$barrier}.".getmypid().'.ready';
touch($ready);

while (! file_exists($barrier)) {
    usleep(10_000);
}

$rawValues = ['symbol' => 'PZU', 'quantity' => '123.45000000000000000001'];

$app->make(PortfolioImportService::class)->persist(
    new ImportBatch('xtb', 'XTB-CONCURRENT-001', $batchIdentity, new DateTimeImmutable('2026-08-01T12:34:56+00:00')),
    [PortfolioImportRow::valid(
        PortfolioImportRow::deterministicIdentity($rawValues, 'statement-row-1'),
        new CanonicalInstrument('PZU.PL'),
        '123.45000000000000000001',
        6789,
        new DateTimeImmutable('2026-08-01T00:00:00+02:00'),
        $rawValues,
    )],
);
