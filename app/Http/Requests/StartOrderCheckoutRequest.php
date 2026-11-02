<?php

namespace App\Http\Requests;

use App\Models\Creator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StartOrderCheckoutRequest extends FormRequest
{
    private const OUT_OF_RANGE = '応援額は0円、または100円から50,000円で指定してください。';

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'support_amount' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) {
                $amount = (int) $value;
                $min = (int) config('fanbox.billing.support_amount_min');
                $max = (int) config('fanbox.billing.support_amount_max');

                if ($amount !== 0 && ($amount < $min || $amount > $max)) {
                    $fail(self::OUT_OF_RANGE);
                }
            }],
        ];
    }

    /**
     * A creator may stop accepting support after the buyer opened the confirm page.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $creator = $this->route('creator');

            if (! $validator->errors()->has('support_amount') && $this->supportAmount() > 0
                && $creator instanceof Creator && ! $creator->accepts_support) {
                $validator->errors()->add('support_amount', 'このクリエイターは現在応援を受け付けていません');
            }
        }];
    }

    /**
     * The support in yen; nothing sent (or an empty field) means none.
     */
    public function supportAmount(): int
    {
        return (int) $this->input('support_amount');
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['support_amount' => '応援額'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['support_amount.integer' => self::OUT_OF_RANGE];
    }
}
