<?php

namespace App\Http\Requests\Creator;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared by StoreProductRequest and UpdateProductRequest. A digital work has no kinds or stock of its own (its single
 * variant holds none), so the variant fields apply to goods only.
 */
trait ProductRules
{
    public const MAX_VARIANTS = 20;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'integer', 'between:50,1000000'],
            'is_published' => ['boolean'],
            'product_category_id' => ['nullable', 'integer', $this->categoryExistsRule()],
        ];

        if ($this->isDigitalWork()) {
            return $rules;
        }

        return [
            ...$rules,
            'variants' => ['required', 'array', 'min:1', 'max:'.self::MAX_VARIANTS],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.name' => ['nullable', 'string', 'max:50', 'distinct'],
            'variants.*.stock' => ['required', 'integer', 'between:0,99999'],
        ];
    }

    /**
     * Whether the form is for a digital work.
     */
    abstract protected function isDigitalWork(): bool;

    /**
     * The category the product already has, if any. It stays valid even after it is archived.
     */
    abstract protected function currentCategoryId(): ?int;

    /**
     * An active category, or the one the product already has. Exists::where(Closure) hands the closure the bare query,
     * so the OR is grouped explicitly to keep it from escaping the id match.
     */
    private function categoryExistsRule(): mixed
    {
        $currentId = $this->currentCategoryId();

        return Rule::exists('product_categories', 'id')->where(
            fn (Builder $query) => $query->where(
                fn (Builder $group) => $group->whereNull('archived_at')
                    ->when($currentId, fn (Builder $q, int $id) => $q->orWhere('id', $id))
            )
        );
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_category_id.integer' => 'カテゴリが正しくありません',
            'product_category_id.exists' => 'カテゴリが正しくありません',
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

            if ($this->isDigitalWork() || count($variants) < 2) {
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
            'product_category_id' => 'カテゴリ',
            'variants' => '種類',
            'variants.*.name' => '種類の名前',
            'variants.*.stock' => '在庫',
        ];
    }
}
