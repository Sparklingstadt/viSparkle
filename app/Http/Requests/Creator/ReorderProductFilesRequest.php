<?php

namespace App\Http\Requests\Creator;

use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReorderProductFilesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('product'));
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
     * The ids must be exactly the work's own files, each once.
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
            $own = $product->files()->pluck('id')->sort()->values()->all();
            $given = array_map(intval(...), $this->array('ids'));
            sort($given);

            if ($own !== $given) {
                $validator->errors()->add('ids', 'ファイルの並び順が正しくありません。');
            }
        }];
    }
}
