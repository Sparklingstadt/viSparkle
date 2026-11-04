<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\CategoryRequest;
use App\Models\ProductCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class CategoryController extends CategoryAdminController
{
    public function index(): Response
    {
        return $this->renderIndex();
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        return $this->storeCategory($request);
    }

    public function update(CategoryRequest $request, ProductCategory $category): RedirectResponse
    {
        return $this->updateCategory($request, $category);
    }

    public function move(Request $request, ProductCategory $category): RedirectResponse
    {
        return $this->moveCategory($request, $category);
    }

    public function archive(Request $request, ProductCategory $category): RedirectResponse
    {
        return $this->archiveCategory($request, $category);
    }

    public function destroy(Request $request, ProductCategory $category): RedirectResponse
    {
        return $this->destroyCategory($request, $category);
    }

    protected function model(): string
    {
        return ProductCategory::class;
    }

    protected function kind(): string
    {
        return 'products';
    }

    protected function actionPrefix(): string
    {
        return 'category';
    }

    protected function inUseMessage(): string
    {
        return '商品が付いているカテゴリは削除できません。アーカイブしてください。';
    }

    protected function usageRelation(): string
    {
        return 'products';
    }
}
