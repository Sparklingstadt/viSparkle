<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use App\Models\Support;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProfileDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->currentPasswordRules(),
        ];
    }

    /**
     * A fan's own supports are cancelled by AccountDeletion. The creator's supporters keep access until their
     * paid period ends, so the creator cannot leave while any support is active (including ones scheduled to stop).
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var User $user */
                $user = $this->user();
                if ($user->isCreator() && Support::active()->exists()) {
                    $validator->errors()->add('password', '有効な支援者がいるため削除できません。すべてのプランの受付を停止して支援の期間が終わるのを待つか、Stripe ダッシュボードでサブスクリプションを解約してください。');
                }
            },
        ];
    }
}
