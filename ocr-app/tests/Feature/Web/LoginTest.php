<?php

use App\Models\User;

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
