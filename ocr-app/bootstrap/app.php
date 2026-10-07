<?php

use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [SetLocale::class]);

        // Reached through an HTTPS forwarding address (VS Code port forwarding) as well as
        // http://localhost:8080: trust the forwarded scheme and host so links, forms and
        // redirects use the address the browser actually opened.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 419: a form whose security token expired, e.g. the login page reopened with Back
        // after logging in. Send the user on instead of showing "Page Expired".
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419 || $request->expectsJson()) {
                return null;
            }

            return $request->user()
                ? redirect()->route('documents.index')
                : redirect()->route('login')->with('status', __('Your session expired. Please log in again.'));
        });
    })->create();
