<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * SC-01 Login. There is one owner account (spec 1.2); create it with
 * `php artisan user:token <email> --password=...`.
 */
class LoginController extends Controller
{
    public function create(): Response
    {
        // Not cached: Back must reload the page with a fresh CSRF token.
        return response()->view('auth.login')->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => __('The email or password is incorrect.')]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('documents.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
