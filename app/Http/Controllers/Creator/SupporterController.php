<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Models\Support;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupporterController extends Controller
{
    public function index(Request $request): Response
    {
        $creator = $request->user()->creator;

        $supporters = $creator->supports()
            ->active()
            ->with(['user', 'plan'])
            ->latest('started_at')
            ->paginate(50)
            ->through(fn (Support $support) => [
                'id' => $support->id,
                'name' => $support->user->name,
                'plan_name' => $support->plan?->name,
                'price' => $support->price,
                'started_at' => $support->started_at,
            ]);

        return Inertia::render('creator/supporters', [
            'plans' => $creator->plans()->withCount('activeSupports')->get()->map->toData()->values(),
            'supporters' => $supporters,
        ]);
    }
}
