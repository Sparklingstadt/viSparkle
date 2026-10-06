<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Http\Requests\Creator\RegisterCreatorRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreatorRegistrationController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        if ($request->user()->creator !== null) {
            return to_route('creator.profile.edit');
        }

        return Inertia::render('creator/register');
    }

    public function store(RegisterCreatorRequest $request): RedirectResponse
    {
        if ($request->user()->creator === null) {
            $request->user()->creator()->create($request->validated());
            Inertia::flash('toast', ['type' => 'success', 'message' => 'クリエイター登録が完了しました。']);
        }

        return to_route('creator.profile.edit');
    }
}
