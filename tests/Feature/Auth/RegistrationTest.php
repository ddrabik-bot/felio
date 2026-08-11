<?php

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

uses(DatabaseMigrations::class);

it('shares registration validation errors with the redirected Inertia page', function (): void {
    $this->from('/register')->post('/register', [
        'name' => '',
        'email' => 'not-an-email',
        'password' => 'short',
        'password_confirmation' => 'different',
    ])->assertRedirect('/register');

    $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => Inertia::getVersion(),
    ])->get('/register')
        ->assertOk()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonStructure([
            'component',
            'props' => ['errors' => ['name', 'email', 'password']],
            'url',
            'version',
        ]);
});

it('registers a user with an email and password then authenticates the session', function (): void {
    $this->post('/register', [
        'name' => 'Daniel',
        'email' => 'daniel@example.test',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertRedirect('/portfolio/onboarding');

    $user = User::query()->where('email', 'daniel@example.test')->sole();

    expect(Hash::check('correct-horse-battery-staple', $user->password))->toBeTrue();
    $this->assertAuthenticatedAs($user);
});
