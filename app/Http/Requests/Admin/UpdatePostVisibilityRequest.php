<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePostVisibilityRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'hidden' => ['required', 'boolean'],
            'reason' => ['nullable', 'required_if_accepted:hidden', 'string', 'max:500'],
        ];
    }
}
