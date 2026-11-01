<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use App\Models\Support;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
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
     * Deleting the account would leave Stripe subscriptions charging, so refuse while any is still renewing.
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

                if ($this->renewing()->where('user_id', $user->id)->exists()) {
                    $validator->errors()->add('password', '支援中のクリエイターがいるため、アカウントを削除できません。先に「支援中」から支援を停止してください。');

                    return;
                }

                $creatorId = $user->creator()->value('id');

                if ($creatorId !== null && $this->renewing()->where('creator_id', $creatorId)->exists()) {
                    $validator->errors()->add('password', '有効な支援者がいるため、アカウントを削除できません。運営にお問い合わせください。');
                }
            },
        ];
    }

    /**
     * Active billing supports that will renew (dummy and cancel-scheduled supports do not).
     *
     * @return Builder<Support>
     */
    private function renewing(): Builder
    {
        return Support::query()
            ->active()
            ->whereNotNull('stripe_subscription_id')
            ->where('cancel_at_period_end', false);
    }
}
