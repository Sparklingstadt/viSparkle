<?php

namespace App\Http\Requests\Creator;

use App\Rules\CreatorSlug;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterCreatorRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', new CreatorSlug, Rule::unique('creators', 'slug')],
            'display_name' => ['required', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['slug' => 'URL', 'display_name' => '表示名'];
    }
}
