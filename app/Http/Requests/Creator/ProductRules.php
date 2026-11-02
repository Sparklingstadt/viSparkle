<?php

namespace App\Http\Requests\Creator;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

/**
 * Shared by StoreProductRequest and UpdateProductRequest.
 */
trait ProductRules
{
    public const MAX_VARIANTS = 20;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'integer', 'between:50,1000000'],
            'variants' => ['required', 'array', 'min:1', 'max:'.self::MAX_VARIANTS],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.name' => ['nullable', 'string', 'max:50', 'distinct'],
            'variants.*.stock' => ['required', 'integer', 'between:0,99999'],
            'is_published' => ['boolean'],
        ];
    }

    /**
     * A product with several kinds needs a name for each, so the buyer can tell them apart.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $variants = $this->array('variants');

            if (count($variants) < 2) {
                return;
            }

            foreach ($variants as $i => $variant) {
                if (trim((string) ($variant['name'] ?? '')) === '') {
                    $validator->errors()->add("variants.{$i}.name", '種類が2つ以上あるときは、それぞれに名前をつけてください。');
                }
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => '商品名',
            'description' => '説明',
            'price' => '価格',
            'variants' => '種類',
            'variants.*.name' => '種類の名前',
            'variants.*.stock' => '在庫',
        ];
    }
}
