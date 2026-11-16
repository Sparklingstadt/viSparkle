<?php

namespace App\Http\Requests\Creator;

use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A new order of a product's images or files: `ids` must be exactly the product's own ones, each once.
 */
abstract class ReorderProductItemsRequest extends FormRequest
{
    /**
     * The ids of the product's own items, in any order.
     *
     * @return array<int, int>
     */
    abstract protected function ownIds(Product $product): array;

    /** The error shown when the ids don't match, e.g. 「画像の並び順が正しくありません。」 */
    abstract protected function mismatchMessage(): string;

    /**
     * Another creator's product is reported as missing (404), never as forbidden.
     */
    public function authorize(): bool
    {
        abort_unless($this->user()->can('manage', $this->route('product')), 404);

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ids' => ['present', 'array'],
            'ids.*' => ['integer'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Product $product */
            $product = $this->route('product');
            $own = $this->ownIds($product);
            sort($own);
            $given = array_map(intval(...), $this->array('ids'));
            sort($given);

            if ($own !== $given) {
                $validator->errors()->add('ids', $this->mismatchMessage());
            }
        }];
    }
}
