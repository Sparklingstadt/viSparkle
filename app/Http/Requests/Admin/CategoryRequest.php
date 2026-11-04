<?php

namespace App\Http\Requests\Admin;

use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a category of either kind. Uniqueness is checked in the table of the
 * route-bound category (ignoring its own row); on store, where nothing is bound,
 * the subclass names the table through table().
 */
class CategoryRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $category = $this->route('category');
        $table = $category instanceof Model ? $category->getTable() : $this->table();
        $ignore = $category instanceof Model ? $category->getKey() : null;

        return [
            'name' => ['required', 'string', 'max:30', Rule::unique($table, 'name')->ignore($ignore)],
            'slug' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique($table, 'slug')->ignore($ignore)],
        ];
    }

    /** Table checked for uniqueness when no category is bound to the route. */
    protected function table(): string
    {
        return (new ProductCategory)->getTable();
    }
}
