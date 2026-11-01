<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateUserSuspensionRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'suspended' => ['required', 'boolean'],
            'reason' => ['nullable', 'required_if_accepted:suspended', 'string', 'max:500'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->boolean('suspended') && $this->route('user')?->is($this->user())) {
                    $validator->errors()->add('suspended', '自分自身を利用停止にすることはできません。');
                }
            },
        ];
    }
}
