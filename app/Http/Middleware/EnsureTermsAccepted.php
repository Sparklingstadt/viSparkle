<?php

namespace App\Http\Middleware;

use App\Services\Legal\LegalDocuments;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a signed-in user to the re-acceptance page while their accepted terms version is not the current one.
 */
class EnsureTermsAccepted
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null
            || ! $request->isMethodSafe()
            || $request->expectsJson()
            || $this->exempt($request)
            || $user->terms_version === LegalDocuments::termsVersion()) {
            return $next($request);
        }

        // Inertia link prefetches (Purpose: prefetch) must not replace the page the user actually asked for.
        if ($request->headers->get('Purpose') === 'prefetch') {
            return redirect()->route('legal.accept');
        }

        // guest() remembers the requested URL so that accepting returns the user there.
        return redirect()->guest(route('legal.accept'));
    }

    private function exempt(Request $request): bool
    {
        $name = $request->route()?->getName();

        return $name !== null && (
            str_starts_with($name, 'legal.')
            || str_starts_with($name, 'stripe.')
            || str_starts_with($name, 'verification.')
            || str_starts_with($name, 'dev.')
            || $name === 'supports.checkout.complete'
            || $name === 'logout'
        );
    }
}
