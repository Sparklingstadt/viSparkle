<?php

namespace App\Http\Requests\Creator;

use App\Models\Plan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePlanRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => [
                'required', 'integer',
                'between:'.config('fanbox.plan_min_price').','.config('fanbox.plan_max_price'),
                Rule::unique('plans', 'price'),
            ],
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('fanbox.image_max_kb')],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if (Plan::query()->count() >= config('fanbox.plans_max')) {
                $validator->errors()->add('price', 'プランは'.config('fanbox.plans_max').'個まで作成できます。');
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'プラン名', 'description' => '説明', 'price' => '月額', 'cover' => 'カバー画像'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['price.unique' => '同じ金額のプランがすでにあります。'];
    }
}
