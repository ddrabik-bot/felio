<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;

uses(DatabaseMigrations::class);

it('shares failed login errors with the redirected Inertia page', function (): void {
    $this->from('/login')->post('/login', [
        'email' => 'unknown@example.test',
        'password' => 'correct-horse-battery-staple',
    ])->assertRedirect('/login');

    $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => Inertia::getVersion(),
    ])->get('/login')
        ->assertOk()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonStructure([
            'component',
            'props' => ['errors' => ['email']],
            'url',
            'version',
        ]);
});

it('logs a registered user in and invalidates the session on logout', function (): void {
    $user = User::factory()->create([
        'email' => 'daniel@example.test',
        'password' => Hash::make('correct-horse-battery-staple'),
    ]);

    $this->post('/login', [
        'email' => 'daniel@example.test',
        'password' => 'correct-horse-battery-staple',
    ])->assertRedirect('/');

    $this->assertAuthenticatedAs($user);

    $this->post('/logout')->assertRedirect('/');

    $this->assertGuest();
});

it('sends a reset link and accepts its password reset token', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'email' => 'daniel@example.test',
        'password' => Hash::make('old-correct-horse-battery-staple'),
    ]);

    $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');

    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-correct-horse-battery-staple',
        'password_confirmation' => 'new-correct-horse-battery-staple',
    ])->assertRedirect('/login');

    expect(Hash::check('new-correct-horse-battery-staple', $user->fresh()->password))->toBeTrue();
});
