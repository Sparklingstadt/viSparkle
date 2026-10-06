<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsCreator
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->creator === null) {
            return to_route('creator.register');
        }

        return $next($request);
    }
}
