<?php

use App\Billing\BillingException;
use App\Http\Middleware\EnsureTermsAccepted;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsCreator;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LogoutSuspendedUsers;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->alias([
            'creator' => EnsureUserIsCreator::class,
            'admin' => EnsureUserIsAdmin::class,
        ]);

        // Post bodies are Tiptap JSON where whitespace-only text nodes are meaningful; the controller trims title/excerpt itself.
        // Global middleware runs before routing, so match the post update endpoint (PUT creator/posts/{id}) by path.
        $keepPostBody = fn (Request $request) => $request->isMethod('PUT') && preg_match('#^creator/posts/\d+$#', $request->path()) === 1;
        $middleware->trimStrings(except: [$keepPostBody]);
        $middleware->convertEmptyStringsToNull(except: [$keepPostBody]);

        $middleware->validateCsrfTokens(except: ['stripe/*']);

        $middleware->web(append: [
            HandleAppearance::class,
            LogoutSuspendedUsers::class,
            HandleInertiaRequests::class,
            EnsureTermsAccepted::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A payment gateway failure is shown as a toast on the page the user came from, not as a 500.
        $exceptions->render(function (BillingException $e, Request $request) {
            // Webhook callers are not browsers: let a gateway failure surface as a plain 500 so Stripe retries.
            if ($request->expectsJson() || $request->is('stripe/*')) {
                return null;
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => '決済サービスに接続できませんでした。時間をおいてお試しください。']);

            return back();
        });
    })->create();
