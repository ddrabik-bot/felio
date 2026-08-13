<?php

use App\Http\Middleware\ForceHttpsForPublicOrigin;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

it('rewrites HTTP redirect locations to HTTPS when forced for the public origin', function (): void {
    config()->set('app.force_https', true);

    $response = (new ForceHttpsForPublicOrigin())->handle(
        Request::create('/login'),
        fn (): RedirectResponse => new RedirectResponse('http://felio.codehappens.dev/login'),
    );

    expect($response->headers->get('Location'))->toBe('https://felio.codehappens.dev/login');
});

it('forces HTTPS absolute URLs when enabled for the public origin', function (): void {
    config()->set('app.url', 'http://localhost');
    config()->set('app.force_https', true);

    (new AppServiceProvider(app()))->boot();

    expect(url('/login'))->toBe('https://localhost/login')
        ->and(url('/portfolio/onboarding'))->toBe('https://localhost/portfolio/onboarding');
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
