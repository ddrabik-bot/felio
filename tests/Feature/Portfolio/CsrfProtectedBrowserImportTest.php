<?php

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(DatabaseMigrations::class);

it('rejects import upload and confirmation without a CSRF token and accepts the token rendered for the same browser session', function (): void {
    Storage::fake('local');
    $user = User::factory()->create();
    DB::table('portfolio_accounts')->insert([
        'user_id' => $user->id,
        'broker' => 'xtb',
        'account_reference' => 'XTB-SYNTHETIC-001',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->withMiddleware(PreventRequestForgery::class);
    app()->instance(PreventRequestForgery::class, new class(app(), app('encrypter')) extends PreventRequestForgery
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    });

    $login = $this->get('/login')->assertOk();
    preg_match('/<meta name="csrf-token" content="([^"]+)">/', $login->getContent(), $matches);
    $token = html_entity_decode($matches[1] ?? '', ENT_QUOTES, 'UTF-8');

    expect($token)->not->toBe('');

    $this->actingAs($user)->withCredentials()->postJson('/portfolio/imports/xtb', ['workbook' => sanitizedXtbUpload()])
        ->assertStatus(419);

    $upload = $this->withHeader('X-CSRF-TOKEN', $token)
        ->postJson('/portfolio/imports/xtb', ['workbook' => sanitizedXtbUpload()])
        ->assertCreated()
        ->assertJsonPath('status', 'READY_FOR_CONFIRMATION');

    $importId = $upload->json('importId');
    expect($importId)->toBeString();

    $this->withoutHeader('X-CSRF-TOKEN')
        ->postJson('/portfolio/imports/xtb/'.$importId.'/confirm', ['mappings' => []])
        ->assertStatus(419);

    $this->withHeader('X-CSRF-TOKEN', $token)
        ->postJson('/portfolio/imports/xtb/'.$importId.'/confirm', ['mappings' => ['PZU' => 'PZU.PL']])
        ->assertOk();

    $this->withoutHeader('X-CSRF-TOKEN')
        ->actingAs($user)
        ->post('/logout')
        ->assertStatus(419);

    $this->actingAs($user)
        ->post('/logout', ['_token' => $token])
        ->assertRedirect('/');
});
