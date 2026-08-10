<?php

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

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
