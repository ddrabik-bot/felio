<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $portfolioAccountId, $firstBatchId, $secondBatchId] = $argv;
DB::reconnect('pgsql');

$remainingBatches = DB::table('portfolio_import_batches')->whereIn('id', [$firstBatchId, $secondBatchId])->count();
$positions = DB::table('portfolio_positions')->where('portfolio_account_id', $portfolioAccountId)->count();
$boundaries = DB::table('portfolio_import_recalculation_boundaries')->whereIn('deleted_import_batch_id', [$firstBatchId, $secondBatchId])->count();

if ($remainingBatches !== 0 || $positions !== 0 || $boundaries !== 2) {
    fwrite(STDERR, json_encode(compact('remainingBatches', 'positions', 'boundaries'), JSON_THROW_ON_ERROR)."\n");
    exit(1);
}
