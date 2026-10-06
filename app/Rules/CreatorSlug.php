<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class CreatorSlug implements ValidationRule
{
    public const PATTERN = '/^[a-z0-9](?:[a-z0-9-]{1,30}[a-z0-9])$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            $fail('URLは半角英小文字・数字・ハイフンで3〜32文字にしてください（先頭と末尾にハイフンは使えません）。');

            return;
        }

        if (in_array($value, config('fanbox.reserved_slugs'), true)) {
            $fail('このURLは使用できません。');
        }
    }
}
