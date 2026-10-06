<?php

use App\Http\Middleware\EnsureUserIsCreator;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->alias(['creator' => EnsureUserIsCreator::class]);

        // Post bodies are Tiptap JSON where whitespace-only text nodes are meaningful; the controller trims title/excerpt itself.
        // Global middleware runs before routing, so match the post update endpoint (PUT creator/posts/{id}) by path.
        $keepPostBody = fn (Request $request) => $request->isMethod('PUT') && preg_match('#^creator/posts/\d+$#', $request->path()) === 1;
        $middleware->trimStrings(except: [$keepPostBody]);
        $middleware->convertEmptyStringsToNull(except: [$keepPostBody]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
