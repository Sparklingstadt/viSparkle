<?php

namespace App\Http\Requests\Creator;

use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreProductFileRequest extends FormRequest
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
