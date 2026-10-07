<?php

use App\Models\User;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;

it('sends guests to the login screen', function (string $url) {
    $this->get($url)->assertRedirect('/login');
})->with(['/', '/documents', '/documents/upload']);

it('logs the owner in and out', function () {
    $user = User::factory()->create(['email' => 'tei@example.com', 'password' => 'secret-pass']);

    $this->get('/login')->assertOk()->assertSee('Log in');
    $this->post('/login', ['email' => 'tei@example.com', 'password' => 'secret-pass'])
        ->assertRedirect('/documents');
    $this->assertAuthenticatedAs($user);

    $this->post('/logout')->assertRedirect('/login');
    $this->assertGuest();
});

it('rejects a wrong password', function () {
    User::factory()->create(['email' => 'tei@example.com', 'password' => 'secret-pass']);

    $this->from('/login')->post('/login', ['email' => 'tei@example.com', 'password' => 'wrong'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['email' => 'The email or password is incorrect.']);
    $this->assertGuest();
});

it('builds https links behind a forwarding proxy (VS Code port forwarding, tunnels)', function () {
    $response = $this->get('/login', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'abc123-8080.asse.devtunnels.ms',
        'X-Forwarded-Port' => '443',
    ]);

    $response->assertOk()
        ->assertSee('href="https://abc123-8080.asse.devtunnels.ms/css/app.css"', false)
        ->assertSee('action="https://abc123-8080.asse.devtunnels.ms/login"', false);
});

it('turns an expired form (419) into a friendly redirect', function () {
    // CSRF is not checked in tests, so throw the same exception the middleware throws.
    Route::middleware('web')->post('/_expired', fn () => throw new TokenMismatchException);

    $this->post('/_expired')
        ->assertRedirect('/login')
        ->assertSessionHas('status', 'Your session expired. Please log in again.');

    // Already logged in (e.g. Back to the old login page after logging in): go to the list.
    $this->actingAs(User::factory()->create())->post('/_expired')->assertRedirect('/documents');

    $this->postJson('/_expired')->assertStatus(419);
});

it('does not let the browser cache the login page', function () {
    expect($this->get('/login')->headers->get('Cache-Control'))->toContain('no-store');
});
