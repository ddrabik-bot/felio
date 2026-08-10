<?php

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;

uses(DatabaseMigrations::class);

it('registers a user with an email and password then authenticates the session', function (): void {
    $this->post('/register', [
        'name' => 'Daniel',
        'email' => 'daniel@example.test',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertRedirect('/');

    $user = User::query()->where('email', 'daniel@example.test')->sole();

    expect(Hash::check('correct-horse-battery-staple', $user->password))->toBeTrue();
    $this->assertAuthenticatedAs($user);
});
