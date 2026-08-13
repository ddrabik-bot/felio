<?php

use App\Application\Portfolio\Xtb\XtbManualImportWorkflow;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $batchId, $barrier] = $argv;
$ready = "{$barrier}.".getmypid().'.ready';
touch($ready);

while (! file_exists($barrier)) {
    usleep(10_000);
}

DB::reconnect('pgsql');
$app->make(XtbManualImportWorkflow::class)->delete((int) $batchId);
