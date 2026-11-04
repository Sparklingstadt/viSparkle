<?php

namespace App\Http\Requests\Creator;

use App\Enums\ProductKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    use ProductRules {
        rules as productRules;
        attributes as productAttributes;
    }

    /**
     * The kind is chosen once, here; updates never change it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(ProductKind::class)],
            ...$this->productRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['kind' => '売るもの', ...$this->productAttributes()];
    }

    protected function isDigitalWork(): bool
    {
        return $this->input('kind') === ProductKind::Digital->value;
    }

    protected function currentCategoryId(): ?int
    {
        return null;
    }
}
