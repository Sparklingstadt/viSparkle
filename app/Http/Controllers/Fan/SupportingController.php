<?php

namespace App\Http\Controllers\Fan;

use App\Http\Controllers\Controller;
use App\Models\Support;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupportingController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $supports = $request->user()->supports()
            ->active()
            ->with(['creator', 'plan'])
            ->latest('started_at')
            ->get()
            ->map(fn (Support $support) => [
                'id' => $support->id,
                'creator' => $support->creator->toSummary(),
                'plan_name' => $support->plan?->name,
                'price' => $support->price,
                'started_at' => $support->started_at,
            ]);

        return Inertia::render('fan/supporting', ['supports' => $supports]);
    }
}
