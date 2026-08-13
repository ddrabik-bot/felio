<?php

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $user = User::factory()->create();
    $this->activePortfolioId = DB::table('portfolio_accounts')->insertGetId([
        'user_id' => $user->id,
        'broker' => 'xtb',
        'account_reference' => 'XTB-SYNTHETIC-001',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->actingAs($user);
});

it('invokes a complete XTB import from a fixture through the HTTP contract in one call', function (): void {
    importXtbFixture($this, mappings: ['PZU' => 'PZU.PL'])
        ->assertOk()
        ->assertJsonPath('status', 'COMPLETED_WITH_WARNINGS')
        ->assertJsonPath('summary.valid', 2)
        ->assertJsonPath('summary.pending', 0)
        ->assertJsonPath('summary.rejected', 2);
});

it('previews a local XTB upload without persistence and requires confirmation before processing', function (): void {
    Storage::fake('local');
    $logger = Log::spy();

    $upload = uploadXtbFixture($this);

    $upload->assertCreated()
        ->assertJsonPath('status', 'READY_FOR_CONFIRMATION');
    assertXtbImportSummary($upload, ['valid' => 0, 'pending' => 2, 'rejected' => 2]);

    $importId = $upload->json('importId');

    expect($importId)->toBeString()
        ->and(DB::table('portfolio_import_batches')->count())->toBe(0)
        ->and(DB::table('portfolio_import_source_rows')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);

    assertNoImportLogs($logger);

    confirmXtbImport($this, $importId, ['PZU' => 'PZU.PL'])->assertOk()
        ->assertJsonPath('status', 'COMPLETED_WITH_WARNINGS')
        ->assertJsonPath('statusHistory', ['UPLOADED', 'ANALYZING', 'READY_FOR_CONFIRMATION', 'CONFIRMED', 'PROCESSING', 'COMPLETED_WITH_WARNINGS'])
        ->assertJsonPath('summary.valid', 2)
        ->assertJsonPath('summary.pending', 0)
        ->assertJsonPath('summary.rejected', 2);

    expect(DB::table('portfolio_import_batches')->count())->toBe(1)
        ->and(DB::table('portfolio_import_batches')->value('status'))->toBe('COMPLETED_WITH_WARNINGS')
        ->and(DB::table('portfolio_import_source_rows')->where('status', 'valid')->count())->toBe(2)
        ->and(DB::table('portfolio_import_source_rows')->where('status', 'rejected')->count())->toBe(2)
        ->and(DB::table('portfolio_import_source_rows')->where('raw_values->sheet', 'Cash Operations')->count())->toBeGreaterThan(0)
        ->and(DB::table('portfolio_import_source_rows')->where('raw_values->source_row_reference', 'Cash Operations:6')->count())->toBe(1)
        ->and(DB::table('portfolio_import_source_rows')->where('diagnostic', 'unsupported_cash_operation')->count())->toBe(1)
        ->and(DB::table('portfolio_import_source_rows')->where('diagnostic', 'unsupported_closed_position')->count())->toBe(1)
        ->and(DB::table('portfolio_import_source_rows')->where('raw_values->sheet', 'Closed Positions')->count())->toBe(1)
        ->and(DB::table('portfolio_import_source_rows')->where('raw_values->sheet', 'Closed Positions')->whereNotNull('canonical_instrument')->count())->toBe(0)
        ->and(DB::table('portfolio_positions')->count())->toBe(0);

    expect(Storage::disk('local')->allFiles())->toBe([]);
    assertNoImportLogs($logger);
});

it('keeps the complete preview lifecycle visible when confirmation mappings fail validation', function (): void {
    Storage::fake('local');

    $upload = uploadXtbFixture($this)->assertCreated();
    $importId = $upload->json('importId');

    $confirmation = $this->postJson("/portfolio/imports/xtb/{$importId}/confirm", []);

    $confirmation->assertUnprocessable()
        ->assertJsonPath('status', 'READY_FOR_CONFIRMATION')
        ->assertJsonPath('statusHistory', ['UPLOADED', 'ANALYZING', 'READY_FOR_CONFIRMATION'])
        ->assertJsonValidationErrors('mappings');

    expect(DB::table('portfolio_import_batches')->count())->toBe(0)
        ->and(DB::table('portfolio_import_source_rows')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
});

it('cleans up a temporary upload when analysis fails and does not persist workbook content', function (): void {
    Storage::fake('local');
    $logger = Log::spy();

    $this->withoutExceptionHandling();

    $exception = null;
    try {
        $this->postJson('/portfolio/imports/xtb', [
            'workbook' => UploadedFile::fake()->createWithContent('broken.xlsx', 'not-an-xlsx-workbook'),
        ]);
    } catch (Throwable $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(InvalidArgumentException::class);

    expect(DB::table('portfolio_import_batches')->count())->toBe(0)
        ->and(DB::table('portfolio_import_source_rows')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    assertNoImportLogs($logger);
});

it('returns a JSON failed status for a malformed upload while deleting its temporary workbook', function (): void {
    Storage::fake('local');

    $this->postJson('/portfolio/imports/xtb', [
        'workbook' => UploadedFile::fake()->createWithContent('broken.xlsx', 'not-an-xlsx-workbook'),
    ])->assertUnprocessable()
        ->assertJsonPath('status', 'FAILED')
        ->assertJsonPath('statusHistory', ['UPLOADED', 'ANALYZING', 'FAILED'])
        ->assertJsonPath('errors.workbook.0', 'The XTB workbook is not a valid XLSX archive.');

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('deletes the relative temporary workbook even when confirmation processing fails', function (): void {
    Storage::fake('local');

    $upload = uploadXtbFixture($this)->assertCreated();
    $importId = $upload->json('importId');
    $path = Storage::disk('local')->allFiles()[0];
    Storage::disk('local')->put($path, 'not-an-xlsx-workbook');

    $this->postJson("/portfolio/imports/xtb/{$importId}/confirm", ['mappings' => ['PZU' => 'PZU.PL']])
        ->assertUnprocessable()
        ->assertJsonPath('status', 'FAILED')
        ->assertJsonPath('statusHistory', ['UPLOADED', 'ANALYZING', 'READY_FOR_CONFIRMATION', 'CONFIRMED', 'PROCESSING', 'FAILED'])
        ->assertJsonPath('errors.workbook.0', 'The XTB workbook is not a valid XLSX archive.');

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('returns the complete failure lifecycle for a generic confirmation processing failure', function (): void {
    Storage::fake('local');

    $upload = uploadXtbFixture($this)->assertCreated();
    $importId = $upload->json('importId');
    $path = Storage::disk('local')->allFiles()[0];
    Storage::disk('local')->put($path, unresolvedRelationshipWorkbookContents());

    $this->postJson("/portfolio/imports/xtb/{$importId}/confirm", ['mappings' => ['PZU' => 'PZU.PL']])
        ->assertServerError()
        ->assertJsonPath('status', 'FAILED')
        ->assertJsonPath('statusHistory', ['UPLOADED', 'ANALYZING', 'READY_FOR_CONFIRMATION', 'CONFIRMED', 'PROCESSING', 'FAILED']);

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('rejects a forged draft path without deleting another local-storage file', function (): void {
    Storage::fake('local');
    $importId = 'a1d9d468-4b7a-446a-a4d9-799df7f71104';
    $protectedPath = 'unrelated-local-file.txt';
    Storage::disk('local')->put($protectedPath, 'must remain');

    $this->withSession([
        'xtb_import_draft:'.$importId => [
            'path' => $protectedPath,
            'portfolioAccountId' => $this->activePortfolioId,
            'statusHistory' => ['UPLOADED', 'ANALYZING', 'READY_FOR_CONFIRMATION'],
        ],
    ])->postJson("/portfolio/imports/xtb/{$importId}/confirm", ['mappings' => ['PZU' => 'PZU.PL']])
        ->assertUnprocessable()
        ->assertJsonPath('status', 'FAILED')
        ->assertJsonPath('statusHistory', ['UPLOADED', 'ANALYZING', 'READY_FOR_CONFIRMATION', 'CONFIRMED', 'PROCESSING', 'FAILED']);

    expect(Storage::disk('local')->exists($protectedPath))->toBeTrue()
        ->and(Storage::disk('local')->get($protectedPath))->toBe('must remain');
});

it('persists unmapped valid rows as pending and can reprocess them without re-uploading the workbook', function (): void {
    $upload = uploadXtbFixture($this);
    $importId = $upload->json('importId');

    confirmXtbImport($this, $importId)
        ->assertOk()
        ->assertJsonPath('status', 'COMPLETED_WITH_WARNINGS')
        ->assertJsonPath('summary.pending', 2);

    $batchId = DB::table('portfolio_import_batches')->value('id');

    expect(DB::table('portfolio_import_source_rows')->where('status', 'pending')->count())->toBe(2)
        ->and(DB::table('portfolio_positions')->count())->toBe(0);

    reprocessXtbImport($this, $batchId, ['PZU' => 'PZU.PL'])->assertOk()
        ->assertJsonPath('status', 'COMPLETED_WITH_WARNINGS')
        ->assertJsonPath('summary.pending', 0);

    expect(DB::table('portfolio_import_source_rows')->where('status', 'valid')->count())->toBe(2)
        ->and(DB::table('portfolio_import_source_rows')->where('status', 'rejected')->count())->toBe(2)
        ->and(DB::table('portfolio_positions')->count())->toBe(0)
        ->and(DB::table('portfolio_import_source_rows')->where('status', 'rejected')->whereNotNull('canonical_instrument')->count())->toBe(0);
});

it('reprocesses a legacy pending XTB row without a persisted price as a cost-unavailable position', function (): void {
    $batchId = DB::table('portfolio_import_batches')->insertGetId([
        'portfolio_account_id' => $this->activePortfolioId,
        'source_batch_identity' => 'legacy-pre-price-xtb',
        'imported_at' => now(),
        'status' => 'COMPLETED_WITH_WARNINGS',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('portfolio_import_source_rows')->insert([
        'portfolio_import_batch_id' => $batchId,
        'source_row_identity' => 'legacy-pre-price-row',
        'status' => 'pending',
        'as_of' => '2026-08-01T00:00:00+00:00',
        'raw_values' => json_encode(['xtb_symbol' => 'PZU', 'xtb_quantity' => '2', 'xtb_operation' => 'buy'], JSON_THROW_ON_ERROR),
        'diagnostic' => 'canonical_instrument_unresolved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    reprocessXtbImport($this, $batchId, ['PZU' => 'PZU.PL'])->assertOk()
        ->assertJsonPath('status', 'COMPLETED');

    $position = DB::table('portfolio_positions')->where('portfolio_account_id', $this->activePortfolioId)->first();
    expect($position->quantity)->toBe('2')
        ->and($position->average_cost_pln_grosze)->toBeNull();
});

it('keeps confirmation idempotent across repeated uploads of the same workbook', function (): void {
    $firstUpload = uploadXtbFixture($this)->assertCreated();
    $firstResult = confirmXtbImport($this, $firstUpload->json('importId'), ['PZU' => 'PZU.PL'])->assertOk();

    $secondUpload = uploadXtbFixture($this)->assertCreated();
    $secondResult = confirmXtbImport($this, $secondUpload->json('importId'), ['PZU' => 'PZU.PL'])->assertOk();

    expect($secondResult->json('batchId'))->toBe($firstResult->json('batchId'))
        ->and(DB::table('portfolio_accounts')->count())->toBe(1)
        ->and(DB::table('portfolio_import_batches')->count())->toBe(1)
        ->and(DB::table('portfolio_import_source_rows')->count())->toBe(4)
        ->and(DB::table('portfolio_positions')->count())->toBe(0);
});

it('deletes an entire import batch only after recording its recalculation boundary', function (): void {
    $upload = uploadXtbFixture($this);
    $importId = $upload->json('importId');
    confirmXtbImport($this, $importId, ['PZU' => 'PZU.PL'])->assertOk();

    $batchId = DB::table('portfolio_import_batches')->value('id');

    deleteXtbImportBatch($this, $batchId)
        ->assertOk()
        ->assertJsonPath('status', 'DELETED');

    expect(DB::table('portfolio_import_batches')->count())->toBe(0)
        ->and(DB::table('portfolio_import_source_rows')->count())->toBe(0)
        ->and(DB::table('portfolio_positions')->count())->toBe(0)
        ->and(DB::table('portfolio_import_recalculation_boundaries')->count())->toBe(1)
        ->and(DB::table('portfolio_import_recalculation_boundaries')->value('deleted_import_batch_id'))->toBe($batchId);
});

it('serializes concurrent deletion and rebuild of distinct batches for one portfolio account', function (): void {
    expect(DB::getDriverName())->toBe('pgsql');

    $preparer = new Process([PHP_BINARY, base_path('tests/Fixtures/Portfolio/PrepareXtbBatchDeletionWorker.php')], base_path());
    $preparer->run();
    expect($preparer->getExitCode(), $preparer->getErrorOutput())->toBe(0);
    $prepared = json_decode($preparer->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    $portfolioAccountId = $prepared['portfolioAccountId'];
    $batchIds = $prepared['batchIds'];
    $barrier = tempnam(sys_get_temp_dir(), 'felio-delete-rebuild-');
    unlink($barrier);
    $worker = base_path('tests/Fixtures/Portfolio/DeleteXtbImportBatchWorker.php');
    $processes = array_map(static fn (int $batchId): Process => new Process([PHP_BINARY, $worker, (string) $batchId, $barrier], base_path()), $batchIds);

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
            if ($process->getExitCode() !== 0) {
                throw new RuntimeException($process->getErrorOutput());
            }
        }

        $verifier = new Process([
            PHP_BINARY,
            base_path('tests/Fixtures/Portfolio/VerifyXtbBatchDeletionWorker.php'),
            (string) $portfolioAccountId,
            ...array_map(static fn (int $batchId): string => (string) $batchId, $batchIds),
        ], base_path());
        $verifier->run();

        if ($verifier->getExitCode() !== 0) {
            throw new RuntimeException($verifier->getErrorOutput());
        }
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

function unresolvedRelationshipWorkbookContents(): string
{
    $path = tempnam(sys_get_temp_dir(), 'xtb-unresolved-relationship-');
    if ($path === false) {
        throw new RuntimeException('Unable to create a sanitized XTB workbook path.');
    }

    $archive = new ZipArchive;
    if ($archive->open($path, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create a sanitized XTB workbook archive.');
    }

    try {
        $archive->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $archive->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Cash Operations" sheetId="1" r:id="rIdMissing"/><sheet name="Closed Positions" sheetId="2" r:id="rId2"/></sheets></workbook>');
        $archive->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId2" Target="worksheets/sheet2.xml"/></Relationships>');
    } finally {
        $archive->close();
    }

    try {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read the sanitized XTB workbook archive.');
        }

        return $contents;
    } finally {
        @unlink($path);
    }
}
