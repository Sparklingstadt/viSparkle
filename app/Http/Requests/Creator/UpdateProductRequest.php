<?php

namespace App\Http\Requests\Creator;

use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    use ProductRules {
        rules as productRules;
    }

    /**
     * Another creator's product is reported as missing (404), never as forbidden.
     */
    public function authorize(): bool
    {
        abort_unless($this->user()->can('manage', $this->route('product')), 404);

        return true;
    }

    /**
     * The edit form also sends, for each existing kind, the stock it was opened with, so only the creator's change is
     * applied (sales made meanwhile stay taken).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if ($this->isDigitalWork()) {
            return $this->productRules();
        }

        return [
            ...$this->productRules(),
            'variants.*.stock_seen' => ['required_with:variants.*.id', 'nullable', 'integer', 'between:0,99999'],
        ];
    }

    protected function isDigitalWork(): bool
    {
        /** @var Product $product */
        $product = $this->route('product');

        return $product->isDigital();
    }

    protected function currentCategoryId(): ?int
    {
        /** @var Product $product */
        $product = $this->route('product');

        return $product->product_category_id;
    }
}
