<?php

namespace App\Http\Requests\Creator;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    use ProductRules;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('product'));
    }
}
