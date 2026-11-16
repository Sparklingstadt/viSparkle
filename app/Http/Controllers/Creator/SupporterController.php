<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Support;
use Inertia\Inertia;
use Inertia\Response;

class SupporterController extends Controller
{
    public function index(): Response
    {
        $supporters = Support::active()
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
            'plans' => Plan::query()->withCount('activeSupports')->orderBy('sort')->orderBy('price')->get()->map->toData()->values(),
            'supporters' => $supporters,
        ]);
    }
}
