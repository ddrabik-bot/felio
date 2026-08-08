<?php

declare(strict_types=1);

use Illuminate\Database\Query\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Tests\Support\XtbImportDatabaseState;
use Tests\TestCase;

function prepareXtbImportDatabaseState(): XtbImportDatabaseState
{
    $state = new XtbImportDatabaseState;
    $state->prepare();

    return $state;
}

function xtbFixturePath(string $fixture = 'synthetic-xtb-statement.xlsx'): string
{
    $path = base_path('tests/Fixtures/Xtb/'.$fixture);

    if (! is_file($path)) {
        throw new RuntimeException('Missing sanitized XTB fixture: '.$fixture);
    }

    return $path;
}

function xtbFixtureContents(string $fixture = 'synthetic-xtb-statement.xlsx'): string
{
    $contents = file_get_contents(xtbFixturePath($fixture));

    if ($contents === false) {
        throw new RuntimeException('Unable to read the sanitized XTB fixture: '.$fixture);
    }

    return $contents;
}

function sanitizedXtbUpload(string $fixture = 'synthetic-xtb-statement.xlsx'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($fixture, xtbFixtureContents($fixture));
}

function temporaryXtbUpload(string $fixture = 'synthetic-xtb-statement.xlsx'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'felio-xtb-upload-');

    if ($path === false) {
        throw new RuntimeException('Unable to create a temporary XTB upload file.');
    }

    try {
        if (! copy(xtbFixturePath($fixture), $path)) {
            throw new RuntimeException('Unable to copy the sanitized XTB fixture: '.$fixture);
        }

        return new UploadedFile($path, $fixture, null, UPLOAD_ERR_OK, true);
    } catch (Throwable $exception) {
        unlink($path);

        throw $exception;
    }
}

function cleanupTemporaryXtbUpload(UploadedFile $upload): void
{
    $path = $upload->getRealPath();

    if (is_string($path) && is_file($path) && ! unlink($path)) {
        throw new RuntimeException('Unable to remove the temporary XTB upload file: '.$path);
    }
}

function uploadXtbFixture(TestCase $test, string $fixture = 'synthetic-xtb-statement.xlsx'): TestResponse
{
    return $test->postJson('/portfolio/imports/xtb', ['workbook' => sanitizedXtbUpload($fixture)]);
}

/** @param array<string, string> $mappings */
function importXtbFixture(TestCase $test, string $fixture = 'synthetic-xtb-statement.xlsx', array $mappings = [], bool $assertSuccessful = false): TestResponse
{
    $upload = uploadXtbFixture($test, $fixture);

    if ($assertSuccessful) {
        $upload->assertCreated();
    }

    $importId = $upload->json('importId');
    if (! is_string($importId) || $importId === '') {
        return $upload;
    }

    $confirmation = confirmXtbImport($test, $importId, $mappings);

    if ($assertSuccessful) {
        $confirmation->assertOk();
    }

    return $confirmation;
}

/** @param array<string, string> $mappings */
function confirmXtbImport(TestCase $test, string $importId, array $mappings = []): TestResponse
{
    return $test->postJson('/portfolio/imports/xtb/'.$importId.'/confirm', ['mappings' => $mappings]);
}

/** @param array<string, string> $mappings */
function reprocessXtbImport(TestCase $test, int $batchId, array $mappings = []): TestResponse
{
    return $test->postJson('/portfolio/import-batches/'.$batchId.'/reprocess', ['mappings' => $mappings]);
}

function deleteXtbImportBatch(TestCase $test, int $batchId): TestResponse
{
    return $test->deleteJson('/portfolio/import-batches/'.$batchId);
}

function resetXtbImportDatabase(): void
{
    prepareXtbImportDatabaseState();
}

function xtbImportBatch(?int $batchId = null): ?object
{
    $query = DB::table('portfolio_import_batches')->orderBy('id');

    if ($batchId !== null) {
        $query->where('id', $batchId);
    }

    return $query->first();
}

/** @return list<object> */
function xtbImportSourceRows(?int $batchId = null): array
{
    $query = DB::table('portfolio_import_source_rows')->orderBy('id');

    if ($batchId !== null) {
        $query->where('portfolio_import_batch_id', $batchId);
    }

    return $query->get()->all();
}

/** @return list<object> */
function xtbImportPositions(?int $accountId = null): array
{
    $query = DB::table('portfolio_positions')->orderBy('id');

    if ($accountId !== null) {
        $query->where('portfolio_account_id', $accountId);
    }

    return $query->get()->all();
}

/** @return list<object> */
function xtbImportRecalculationBoundaries(?int $batchId = null): array
{
    $query = DB::table('portfolio_import_recalculation_boundaries')->orderBy('id');

    if ($batchId !== null) {
        $query->where('deleted_import_batch_id', $batchId);
    }

    return $query->get()->all();
}

/** @param array{valid: int, pending: int, rejected: int} $summary */
function assertXtbImportSummary(TestResponse $response, array $summary): void
{
    $response->assertJsonPath('summary.valid', $summary['valid'])
        ->assertJsonPath('summary.pending', $summary['pending'])
        ->assertJsonPath('summary.rejected', $summary['rejected']);
}

function assertNoImportLogs(object $logger): void
{
    foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $level) {
        $logger->shouldNotHaveReceived($level);
    }
}

function importSourceRows(): array
{
    return xtbImportSourceRows();
}

function spyOnImportLogs(): object
{
    Log::spy();

    return Log::getFacadeRoot();
}

/**
 * @param  array<string, mixed>  $batchAttributes
 * @param  list<array<string, mixed>>  $sourceRows
 * @param  list<array<string, mixed>>  $positions
 */
function assertXtbImportPersisted(array $batchAttributes, array $sourceRows = [], array $positions = []): object
{
    $batchQuery = DB::table('portfolio_import_batches');
    foreach ($batchAttributes as $column => $value) {
        $batchQuery->where($column, $value);
    }

    $batch = $batchQuery->first();
    Assert::assertNotNull($batch, 'Expected an import batch matching '.json_encode($batchAttributes, JSON_THROW_ON_ERROR).' to be persisted.');

    assertXtbImportRecords(
        DB::table('portfolio_import_source_rows')->where('portfolio_import_batch_id', $batch->id),
        $sourceRows,
        'source row',
    );
    assertXtbImportRecords(
        DB::table('portfolio_positions')->where('portfolio_account_id', $batch->portfolio_account_id),
        $positions,
        'normalized position',
    );

    return $batch;
}

/** @param array<string, mixed> $batchAttributes */
function assertXtbImportFailed(array $batchAttributes = []): void
{
    $batchQuery = DB::table('portfolio_import_batches');
    foreach ($batchAttributes as $column => $value) {
        $batchQuery->where($column, $value);
    }

    Assert::assertSame(0, $batchQuery->count(), 'Expected no persisted import batch matching '.json_encode($batchAttributes, JSON_THROW_ON_ERROR).'.');
}

function assertLogContains(object $logger, string $message, string $level = 'warning'): void
{
    $logger->shouldHaveReceived($level)
        ->withArgs(static fn (mixed $actualMessage): bool => str_contains((string) $actualMessage, $message))
        ->atLeast()
        ->once();
}

/**
 * @param  Builder  $query
 * @param  list<array<string, mixed>>  $expectedRecords
 */
function assertXtbImportRecords(object $query, array $expectedRecords, string $recordType): void
{
    Assert::assertSame(count($expectedRecords), $query->count(), 'Expected exactly '.count($expectedRecords).' persisted '.$recordType.' record(s).');

    foreach ($expectedRecords as $attributes) {
        $recordQuery = clone $query;
        foreach ($attributes as $column => $value) {
            $recordQuery->where($column, $value);
        }

        Assert::assertNotNull($recordQuery->first(), 'Expected persisted '.$recordType.' matching '.json_encode($attributes, JSON_THROW_ON_ERROR).'.');
    }
}
