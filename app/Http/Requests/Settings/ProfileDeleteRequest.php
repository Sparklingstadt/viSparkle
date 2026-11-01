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
     * A fan's own supports are cancelled by AccountDeletion. A creator's supporters keep access until their
     * paid period ends, so refuse while any support is active (including ones scheduled to stop).
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
                $creatorId = $user->creator()->value('id');

                if ($creatorId !== null && Support::active()->where('creator_id', $creatorId)->exists()) {
                    $validator->errors()->add('password', '有効な支援者がいるため削除できません。『活動を終了する』で支援を停止し、期間の終了をお待ちください。');
                }
            },
        ];
    }
}
