<?php

namespace App\Http\Requests\Creator;

use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreProductFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('product'));
    }

    /**
     * Any type, up to the size of a post attachment.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.config('fanbox.attachment_max_kb')],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            /** @var Product $product */
            $product = $this->route('product');
            $max = (int) config('fanbox.product_files_max');

            if ($product->files()->count() >= $max) {
                $validator->errors()->add('file', "ファイルは{$max}件までです。");
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['file' => 'ファイル'];
    }
}
