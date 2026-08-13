<?php

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

it('requires a session for portfolio valuation and all import endpoints', function (): void {
    $this->get('/portfolio/valuation?date=2026-01-02')->assertRedirect('/login');
    $this->get('/portfolio/imports/xtb')->assertUnauthorized();
    $this->postJson('/portfolio/imports/xtb')->assertUnauthorized();
    $this->postJson('/portfolio/imports/xtb/missing/confirm', ['mappings' => []])->assertUnauthorized();
    $this->postJson('/portfolio/import-batches/1/reprocess', ['mappings' => []])->assertUnauthorized();
    $this->deleteJson('/portfolio/import-batches/1')->assertUnauthorized();
});

it('renders an authenticated import screen with the user active portfolio', function (): void {
    $user = User::factory()->create();
    $accountId = activePortfolioFor($user, 'XTB-SYNTHETIC-001');

    $this->actingAs($user)->get('/portfolio/imports/xtb')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Portfolio/XtbImport', false)
            ->where('activePortfolio.id', $accountId)
            ->where('activePortfolio.accountReference', 'XTB-SYNTHETIC-001')
            ->has('portfolios', 1)
            ->has('batches', 0)
        );
});

it('onboards a registered user into an active XTB portfolio before the browser import flow', function (): void {
    Storage::fake('local');

    $this->post('/register', [
        'name' => 'Portfolio owner',
        'email' => 'portfolio-owner@example.test',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertRedirect('/portfolio/onboarding');

    $this->get('/portfolio/onboarding')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Portfolio/Onboarding', false));

    $this->post('/portfolio/onboarding')
        ->assertRedirect('/portfolio/imports/xtb');

    $user = User::query()->where('email', 'portfolio-owner@example.test')->sole();
    $accountId = DB::table('portfolio_accounts')
        ->where('user_id', $user->id)
        ->where('broker', 'xtb')
        ->where('is_active', true)
        ->value('id');
    expect($accountId)->not->toBeNull()
        ->and(DB::table('portfolio_accounts')->where('id', $accountId)->value('account_reference'))->toBeNull();

    $upload = $this->postJson('/portfolio/imports/xtb', ['workbook' => sanitizedXtbUpload()]);
    $upload->assertCreated()
        ->assertJsonPath('activePortfolio.id', $accountId)
        ->assertJsonPath('summary.pending', 2)
        ->assertJsonPath('rows.0.status', 'pending')
        ->assertJsonPath('rows.0.sourceSymbol', 'PZU')
        ->assertJsonPath('rows.2.status', 'rejected');

    $this->postJson('/portfolio/imports/xtb/'.$upload->json('importId').'/confirm', ['mappings' => ['PZU' => 'PZU.PL']])
        ->assertOk()
        ->assertJsonPath('summary.valid', 2)
        ->assertJsonPath('summary.pending', 0);
});

it('keeps exactly one active portfolio when PostgreSQL onboarding requests race', function (): void {
    expect(DB::getDriverName())->toBe('pgsql');

    $user = User::factory()->create();
    $barrier = tempnam(sys_get_temp_dir(), 'felio-portfolio-onboarding-');
    unlink($barrier);
    $worker = base_path('tests/Fixtures/Portfolio/OnboardPortfolioWorker.php');
    $processes = array_map(
        static fn (int $workerNumber): Process => new Process([
            PHP_BINARY,
            $worker,
            (string) $user->id,
            'XTB-CONCURRENT-'.$workerNumber.'-'.bin2hex(random_bytes(4)),
            $barrier,
        ], base_path()),
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

        expect(DB::table('portfolio_accounts')->where('user_id', $user->id)->where('is_active', true)->count())->toBe(1);
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

it('ships a browser import component that includes the Laravel CSRF token on every state-changing fetch, uses a native tokenized logout form, and submits selected mappings', function (): void {
    $component = file_get_contents(resource_path('js/Pages/Portfolio/XtbImport.vue'));

    expect($component)
        ->toContain("document.querySelector('meta[name=\"csrf-token\"]')")
        ->toContain("'X-CSRF-TOKEN': csrfToken")
        ->toContain('JSON.stringify({ mappings: selectedMappings() })')
        ->toContain('action="/logout" method="post"')
        ->toContain('name="_token" :value="csrfToken"')
        ->not->toContain('@submit.prevent="logout"')
        ->toContain('preview.rows')
        ->toContain('mappingSymbols');
});

it('binds the valuation dashboard to the authenticated user active portfolio', function (): void {
    $user = User::factory()->create();
    $accountId = activePortfolioFor($user, 'XTB-SYNTHETIC-001');

    $this->actingAs($user)->get('/portfolio/valuation?date=2026-01-02')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Portfolio/ValuationDashboard', false)
            ->where('activePortfolio.id', $accountId)
            ->where('activePortfolio.accountReference', 'XTB-SYNTHETIC-001')
        );
});

it('keeps the browser upload preview confirmation and batches inside the active portfolio', function (): void {
    Storage::fake('local');
    $user = User::factory()->create();
    $accountId = activePortfolioFor($user, 'XTB-SYNTHETIC-001');

    $upload = $this->actingAs($user)->postJson('/portfolio/imports/xtb', ['workbook' => sanitizedXtbUpload()]);
    $upload->assertCreated()
        ->assertJsonPath('status', 'READY_FOR_CONFIRMATION')
        ->assertJsonPath('activePortfolio.id', $accountId)
        ->assertJsonPath('summary.valid', 0)
        ->assertJsonPath('summary.pending', 2)
        ->assertJsonPath('summary.rejected', 2);

    $this->actingAs($user)->postJson('/portfolio/imports/xtb/'.$upload->json('importId').'/confirm', ['mappings' => ['PZU' => 'PZU.PL']])
        ->assertOk()
        ->assertJsonPath('status', 'COMPLETED_WITH_WARNINGS');

    expect(DB::table('portfolio_import_batches')->where('portfolio_account_id', $accountId)->count())->toBe(1);

    $this->actingAs($user)->get('/portfolio/imports/xtb')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('batches', 1)
            ->where('batches.0.portfolioAccountId', $accountId)
            ->where('batches.0.status', 'COMPLETED_WITH_WARNINGS')
        );
});

it('imports a workbook from a different XTB account into the currently active portfolio', function (): void {
    Storage::fake('local');
    $user = User::factory()->create();
    $accountId = activePortfolioFor($user, 'XTB-DIFFERENT-ACTIVE-ACCOUNT');

    $upload = $this->actingAs($user)->postJson('/portfolio/imports/xtb', ['workbook' => sanitizedXtbUpload()]);
    $upload->assertCreated()
        ->assertJsonPath('activePortfolio.id', $accountId);

    $this->actingAs($user)->postJson('/portfolio/imports/xtb/'.$upload->json('importId').'/confirm', ['mappings' => ['PZU' => 'PZU.PL']])
        ->assertOk()
        ->assertJsonPath('status', 'COMPLETED_WITH_WARNINGS');

    expect(DB::table('portfolio_import_batches')->where('portfolio_account_id', $accountId)->count())->toBe(1)
        ->and(DB::table('portfolio_import_batches')->where('portfolio_account_id', '!=', $accountId)->count())->toBe(0)
        ->and(DB::table('portfolio_import_source_rows')->count())->toBe(4)
        ->and(DB::table('portfolio_positions')->where('portfolio_account_id', $accountId)->count())->toBe(1)
        ->and(DB::table('portfolio_accounts')->where('account_reference', 'XTB-SYNTHETIC-001')->exists())->toBeFalse();
});

it('cleans up a pending workbook when the active portfolio changes before confirmation', function (): void {
    Storage::fake('local');
    $user = User::factory()->create();
    $firstAccountId = activePortfolioFor($user, 'XTB-SYNTHETIC-001');

    $upload = $this->actingAs($user)->postJson('/portfolio/imports/xtb', ['workbook' => sanitizedXtbUpload()]);
    $importId = $upload->json('importId');
    expect($importId)->toBeString();
    Storage::disk('local')->assertExists('xtb-imports/'.$importId.'.xlsx');

    DB::table('portfolio_accounts')->where('id', $firstAccountId)->update(['is_active' => false]);
    DB::table('portfolio_accounts')->insert([
        'user_id' => $user->id,
        'broker' => 'xtb',
        'account_reference' => 'XTB-SYNTHETIC-002',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)->postJson('/portfolio/imports/xtb/'.$importId.'/confirm', ['mappings' => []])
        ->assertUnprocessable();

    Storage::disk('local')->assertMissing('xtb-imports/'.$importId.'.xlsx');
});

it('does not expose another user batches to destroy or reprocess', function (): void {
    $owner = User::factory()->create();
    $ownerAccountId = activePortfolioFor($owner, 'XTB-SYNTHETIC-001');
    $batchId = DB::table('portfolio_import_batches')->insertGetId([
        'portfolio_account_id' => $ownerAccountId,
        'source_batch_identity' => 'owner-private-batch',
        'status' => 'COMPLETED',
        'imported_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $other = User::factory()->create();
    activePortfolioFor($other, 'XTB-OTHER-001');

    $this->actingAs($other)->postJson("/portfolio/import-batches/{$batchId}/reprocess", ['mappings' => []])->assertNotFound();
    $this->actingAs($other)->deleteJson("/portfolio/import-batches/{$batchId}")->assertNotFound();
    expect(DB::table('portfolio_import_batches')->where('id', $batchId)->exists())->toBeTrue();
});

function activePortfolioFor(User $user, string $accountReference): int
{
    return DB::table('portfolio_accounts')->insertGetId([
        'user_id' => $user->id,
        'broker' => 'xtb',
        'account_reference' => $accountReference,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
