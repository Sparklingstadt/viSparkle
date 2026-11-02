<?php

namespace App\Http\Requests\Creator;

use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreProductImageRequest extends FormRequest
{
    public const MAX_IMAGES = 10;

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
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('fanbox.image_max_kb')],
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

            if ($product->images()->count() >= self::MAX_IMAGES) {
                $validator->errors()->add('image', '画像は'.self::MAX_IMAGES.'枚までです。');
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
