<?php

namespace App\Http\Requests\Creator;

use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreProductImageRequest extends FormRequest
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
            'image' => ['required', 'file', 'mimes:'.implode(',', config('fanbox.still_image_mimes')), 'max:'.config('fanbox.image_max_kb')],
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

            $max = (int) config('fanbox.product_images_max');

            if ($product->images()->count() >= $max) {
                $validator->errors()->add('image', "画像は{$max}枚までです。");
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['image' => '画像'];
    }
}
