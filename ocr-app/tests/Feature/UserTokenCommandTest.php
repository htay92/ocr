<?php

use App\Models\User;

it('creates the owner and prints a working API token', function () {
    $this->artisan('user:token', ['email' => 'tei@example.com', '--password' => 'secret-pass'])
        ->expectsOutputToContain('Created user tei@example.com')
        ->assertSuccessful();

    $user = User::firstWhere('email', 'tei@example.com');
    expect($user->tokens)->toHaveCount(1);
});

it('adds a token for an existing user without changing the password', function () {
    $user = User::factory()->create(['email' => 'tei@example.com']);
    $hash = $user->password;

    $this->artisan('user:token', ['email' => 'tei@example.com'])->assertSuccessful();

    expect($user->fresh()->password)->toBe($hash)
        ->and($user->tokens()->count())->toBe(1);
});
