<?php

namespace App\Http\Requests\Creator;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    use ProductRules {
        rules as productRules;
    }

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('product'));
    }

    /**
     * The edit form also sends the stock it was opened with, so only the creator's change is applied (sales made
     * meanwhile stay taken).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->productRules(),
            'stock_seen' => ['required', 'integer', 'between:0,99999'],
        ];
    }
}
