<?php

namespace App\Http\Requests\Creator;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCreatorProfileRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $image = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('fanbox.image_max_kb')];

        return [
            'display_name' => ['required', 'string', 'max:50'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'avatar' => $image,
            'header' => $image,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['display_name' => '表示名', 'bio' => '自己紹介', 'avatar' => 'アイコン', 'header' => 'ヘッダー画像'];
    }
}
