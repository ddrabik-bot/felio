<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DB::reconnect('pgsql');

$portfolioAccountId = DB::table('portfolio_accounts')->insertGetId([
    'broker' => 'xtb',
    'account_reference' => 'XTB-DELETE-CONCURRENT-'.bin2hex(random_bytes(4)),
    'is_active' => false,
    'created_at' => now(),
    'updated_at' => now(),
]);
$batchIds = collect(['delete-concurrent-one', 'delete-concurrent-two'])->map(function (string $identity, int $index) use ($portfolioAccountId): int {
    $batchId = DB::table('portfolio_import_batches')->insertGetId([
        'portfolio_account_id' => $portfolioAccountId,
        'source_batch_identity' => $identity.'-'.bin2hex(random_bytes(4)),
        'imported_at' => now()->addSeconds($index),
        'status' => 'COMPLETED',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('portfolio_import_source_rows')->insert([
        'portfolio_import_batch_id' => $batchId,
        'source_row_identity' => "{$identity}-row",
        'status' => 'valid',
        'canonical_instrument' => 'PZU.PL',
        'quantity' => '1',
        'average_cost_pln_grosze' => 1000 + $index,
        'as_of' => now()->addSeconds($index),
        'raw_values' => json_encode(['source' => 'concurrency-regression'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $batchId;
})->all();

echo json_encode(['portfolioAccountId' => $portfolioAccountId, 'batchIds' => $batchIds], JSON_THROW_ON_ERROR);
