<?php

namespace App\Http\Requests\Creator;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Shared by StoreProductRequest and UpdateProductRequest.
 */
trait ProductRules
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'integer', 'between:50,1000000'],
            'stock' => ['required', 'integer', 'between:0,99999'],
            'is_published' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => '商品名', 'description' => '説明', 'price' => '価格', 'stock' => '在庫'];
    }
}
