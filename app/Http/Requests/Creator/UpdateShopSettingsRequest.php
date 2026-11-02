<?php

namespace App\Http\Requests\Creator;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateShopSettingsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'shipping_fee' => ['required', 'integer', 'between:0,100000'],
            'shop_notice' => ['nullable', 'string', 'max:2000'],
            'shop_contact_email' => ['nullable', 'email', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['shipping_fee' => '送料', 'shop_notice' => 'お知らせ', 'shop_contact_email' => '連絡先メールアドレス'];
    }
}
