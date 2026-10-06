<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Http\Requests\Creator\StorePlanRequest;
use App\Http\Requests\Creator\UpdatePlanRequest;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    public function index(Request $request): Response
    {
        $plans = $request->user()->creator->plans()->withCount('activeSupports')->get();

        return Inertia::render('creator/plans/index', [
            'plans' => $plans->map->toData()->values(),
            'limits' => [
                'min' => config('fanbox.plan_min_price'),
                'max' => config('fanbox.plan_max_price'),
                'count' => config('fanbox.plans_per_creator'),
            ],
        ]);
    }

    public function store(StorePlanRequest $request): RedirectResponse
    {
        $creator = $request->user()->creator;
        $plan = $creator->plans()->make($request->safe()->only(['name', 'description', 'price']));
        $plan->sort = $creator->plans()->count();

        if ($request->hasFile('cover')) {
            $plan->cover_path = $this->storeCover($creator->id, $request->file('cover'));
        }

        $plan->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'プランを作成しました。']);

        return to_route('creator.plans.index');
    }

    public function update(UpdatePlanRequest $request, Plan $plan): RedirectResponse
    {
        abort_unless($request->user()->can('manage', $plan), 404);

        $oldCover = null;

        if ($request->hasFile('cover')) {
            $oldCover = $plan->cover_path;
            $plan->cover_path = $this->storeCover($plan->creator_id, $request->file('cover'));
        }

        $plan->fill([
            ...$request->safe()->only(['name', 'description']),
            'is_accepting' => $request->boolean('is_accepting'),
        ])->save();

        if ($oldCover !== null) {
            Storage::disk('public')->delete($oldCover);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'プランを更新しました。']);

        return to_route('creator.plans.index');
    }

    public function destroy(Request $request, Plan $plan): RedirectResponse
    {
        abort_unless($request->user()->can('manage', $plan), 404);

        if ($plan->activeSupports()->exists()) {
            throw ValidationException::withMessages([
                'plan' => '支援者がいるプランは削除できません。受付を停止してください。',
            ]);
        }

        $plan->delete();

        if ($plan->cover_path !== null) {
            Storage::disk('public')->delete($plan->cover_path);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'プランを削除しました。']);

        return to_route('creator.plans.index');
    }

    private function storeCover(int $creatorId, UploadedFile $file): string
    {
        return $file->storeAs("creators/{$creatorId}/plans", Str::lower((string) Str::ulid()).'.'.$file->extension(), 'public');
    }
}
