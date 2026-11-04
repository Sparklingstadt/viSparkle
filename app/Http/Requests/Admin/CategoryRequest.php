<?php

namespace App\Http\Requests\Admin;

use App\Models\ProductCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $category = $this->route('category');
        $ignore = $category instanceof ProductCategory ? $category->id : null;

        return [
            'name' => ['required', 'string', 'max:30', Rule::unique('product_categories', 'name')->ignore($ignore)],
            'slug' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('product_categories', 'slug')->ignore($ignore)],
        ];
    }
}
