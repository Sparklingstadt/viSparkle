<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CategoryRequest;
use App\Models\AdminAction;
use App\Models\ProductCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(): Response
    {
        $categories = ProductCategory::query()
            ->withCount('products')
            ->ordered()
            ->get()
            ->map(fn (ProductCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'archived' => $category->isArchived(),
                'products_count' => (int) $category->products_count,
            ]);

        return Inertia::render('admin/categories', ['categories' => $categories]);
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $category = new ProductCategory($request->validated());
            $category->sort_order = ProductCategory::nextSortOrder();
            $category->save();

            AdminAction::record($request->user(), 'category.created', $category);
        });

        return $this->done('カテゴリを追加しました。');
    }

    public function update(CategoryRequest $request, ProductCategory $category): RedirectResponse
    {
        $category->update($request->validated());
        AdminAction::record($request->user(), 'category.updated', $category);

        return $this->done('カテゴリを更新しました。');
    }

    public function move(Request $request, ProductCategory $category): RedirectResponse
    {
        $direction = $request->validate(['direction' => ['required', 'in:up,down']])['direction'];

        DB::transaction(function () use ($request, $category, $direction) {
            // Re-number first so that equal sort_order values still swap reliably.
            $ordered = ProductCategory::query()->ordered()->lockForUpdate()->get()->values();
            foreach ($ordered as $position => $item) {
                if ($item->sort_order !== $position) {
                    $item->forceFill(['sort_order' => $position])->save();
                }
            }

            $index = $ordered->search(fn (ProductCategory $item) => $item->is($category));
            $current = $index === false ? null : $ordered->get($index);
            $neighbour = $index === false ? null : $ordered->get($direction === 'up' ? $index - 1 : $index + 1);

            // At either end there is nothing to swap with.
            if ($current === null || $neighbour === null) {
                return;
            }

            [$current->sort_order, $neighbour->sort_order] = [$neighbour->sort_order, $current->sort_order];
            $current->save();
            $neighbour->save();

            AdminAction::record($request->user(), 'category.moved', $category, $direction);
        });

        return $this->done('カテゴリを更新しました。');
    }

    public function archive(Request $request, ProductCategory $category): RedirectResponse
    {
        $archived = $request->validate(['archived' => ['required', 'boolean']])['archived'];

        $category->forceFill(['archived_at' => $archived ? ($category->archived_at ?? now()) : null])->save();
        AdminAction::record($request->user(), $archived ? 'category.archived' : 'category.unarchived', $category);

        return $this->done($archived ? 'カテゴリをアーカイブしました。' : 'カテゴリを再開しました。');
    }

    public function destroy(Request $request, ProductCategory $category): RedirectResponse
    {
        DB::transaction(function () use ($request, $category) {
            if ($category->products()->exists()) {
                throw ValidationException::withMessages(['category' => '商品が付いているカテゴリは削除できません。アーカイブしてください。']);
            }

            AdminAction::record($request->user(), 'category.deleted', $category);
            $category->delete();
        });

        return $this->done('カテゴリを削除しました。');
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
