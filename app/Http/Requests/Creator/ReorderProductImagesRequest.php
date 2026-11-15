<?php

namespace App\Http\Requests\Creator;

use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReorderProductImagesRequest extends FormRequest
{
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
     * The ids must be exactly the product's own images, each once.
     *
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
            $own = $product->images()->pluck('id')->sort()->values()->all();
            $given = array_map(intval(...), $this->array('ids'));
            sort($given);

            if ($own !== $given) {
                $validator->errors()->add('ids', '画像の並び順が正しくありません。');
            }
        }];
    }
}
