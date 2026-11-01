<?php

namespace App\Http\Controllers\Legal;

use App\Http\Controllers\Controller;
use App\Services\Legal\LegalDocuments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AcceptTermsController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('legal/accept', ['version' => LegalDocuments::termsVersion()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->forceFill([
            'terms_version' => LegalDocuments::termsVersion(),
            'terms_accepted_at' => now(),
        ])->save();

        return redirect()->intended('/feed');
    }
}
