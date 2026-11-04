<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CategoryRequest;
use App\Models\AdminAction;
use App\Models\PostCategory;
use App\Models\ProductCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

abstract class CategoryAdminController extends Controller
{
    /** @return class-string<ProductCategory|PostCategory> */
    abstract protected function model(): string;

    /** @return 'products'|'posts' */
    abstract protected function kind(): string;

    /** Prefix of the AdminAction names: 'category' or 'post_category'. */
    abstract protected function actionPrefix(): string;

    abstract protected function inUseMessage(): string;

    /** Name of the relation counted as "usage" ('products' or 'posts'). */
    abstract protected function usageRelation(): string;

    protected function renderIndex(): Response
    {
        $model = $this->model();
        $countKey = $this->usageRelation().'_count';

        $categories = $model::query()
            ->withCount($this->usageRelation())
            ->ordered()
            ->get()
            ->map(fn (ProductCategory|PostCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'archived' => $category->isArchived(),
                'usage_count' => (int) $category->{$countKey},
            ]);

        return Inertia::render('admin/categories', ['kind' => $this->kind(), 'categories' => $categories]);
    }

    protected function storeCategory(CategoryRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $model = $this->model();
            $category = new $model($request->validated());
            $category->sort_order = $model::nextSortOrder();
            $category->save();

            AdminAction::record($request->user(), $this->actionPrefix().'.created', $category);
        });

        return $this->done('カテゴリを追加しました。');
    }

    protected function updateCategory(CategoryRequest $request, ProductCategory|PostCategory $category): RedirectResponse
    {
        $category->update($request->validated());
        AdminAction::record($request->user(), $this->actionPrefix().'.updated', $category);

        return $this->done('カテゴリを更新しました。');
    }

    protected function moveCategory(Request $request, ProductCategory|PostCategory $category): RedirectResponse
    {
        $direction = $request->validate(['direction' => ['required', 'in:up,down']])['direction'];

        DB::transaction(function () use ($request, $category, $direction) {
            // Re-number first so that equal sort_order values still swap reliably.
            $ordered = $this->model()::query()->ordered()->lockForUpdate()->get()->values();
            foreach ($ordered as $position => $item) {
                if ($item->sort_order !== $position) {
                    $item->forceFill(['sort_order' => $position])->save();
                }
            }

            $index = $ordered->search(fn (ProductCategory|PostCategory $item) => $item->is($category));
            $current = $index === false ? null : $ordered->get($index);
            $neighbour = $index === false ? null : $ordered->get($direction === 'up' ? $index - 1 : $index + 1);

            // At either end there is nothing to swap with.
            if ($current === null || $neighbour === null) {
                return;
            }

            [$current->sort_order, $neighbour->sort_order] = [$neighbour->sort_order, $current->sort_order];
            $current->save();
            $neighbour->save();

            AdminAction::record($request->user(), $this->actionPrefix().'.moved', $category, $direction);
        });

        return $this->done('カテゴリを更新しました。');
    }

    protected function archiveCategory(Request $request, ProductCategory|PostCategory $category): RedirectResponse
    {
        $archived = $request->validate(['archived' => ['required', 'boolean']])['archived'];

        $category->forceFill(['archived_at' => $archived ? ($category->archived_at ?? now()) : null])->save();
        AdminAction::record($request->user(), $this->actionPrefix().($archived ? '.archived' : '.unarchived'), $category);

        return $this->done($archived ? 'カテゴリをアーカイブしました。' : 'カテゴリを再開しました。');
    }

    protected function destroyCategory(Request $request, ProductCategory|PostCategory $category): RedirectResponse
    {
        DB::transaction(function () use ($request, $category) {
            if ($category->{$this->usageRelation()}()->exists()) {
                throw ValidationException::withMessages(['category' => $this->inUseMessage()]);
            }

            AdminAction::record($request->user(), $this->actionPrefix().'.deleted', $category);
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
