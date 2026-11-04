<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\PostCategoryRequest;
use App\Models\PostCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class PostCategoryController extends CategoryAdminController
{
    public function index(): Response
    {
        return $this->renderIndex();
    }

    public function store(PostCategoryRequest $request): RedirectResponse
    {
        return $this->storeCategory($request);
    }

    public function update(PostCategoryRequest $request, PostCategory $category): RedirectResponse
    {
        return $this->updateCategory($request, $category);
    }

    public function move(Request $request, PostCategory $category): RedirectResponse
    {
        return $this->moveCategory($request, $category);
    }

    public function archive(Request $request, PostCategory $category): RedirectResponse
    {
        return $this->archiveCategory($request, $category);
    }

    public function destroy(Request $request, PostCategory $category): RedirectResponse
    {
        return $this->destroyCategory($request, $category);
    }

    protected function model(): string
    {
        return PostCategory::class;
    }

    protected function kind(): string
    {
        return 'posts';
    }

    protected function actionPrefix(): string
    {
        return 'post_category';
    }

    protected function inUseMessage(): string
    {
        return '投稿が付いているカテゴリは削除できません。アーカイブしてください。';
    }

    protected function usageRelation(): string
    {
        return 'posts';
    }
}
