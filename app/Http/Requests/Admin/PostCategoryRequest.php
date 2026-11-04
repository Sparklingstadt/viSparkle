<?php

namespace App\Http\Requests\Admin;

use App\Models\PostCategory;

class PostCategoryRequest extends CategoryRequest
{
    protected function table(): string
    {
        return (new PostCategory)->getTable();
    }
}
