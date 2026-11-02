<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use App\Enums\OrderStatus;
use App\Enums\ProductKind;
use App\Models\Order;
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
     * paid period ends, so refuse while any support is active (including ones scheduled to stop), and while any of the
     * creator's orders is awaiting payment or not yet shipped.
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

                if ($creatorId === null) {
                    return;
                }

                if (Support::active()->where('creator_id', $creatorId)->exists()) {
                    $validator->errors()->add('password', '有効な支援者がいるため削除できません。『活動を終了する』で支援を停止し、期間の終了をお待ちください。');
                }

                // The creator's products go with the account: never leave a buyer's paid order with nobody to ship it.
                // A paid order of digital works only has nothing to ship (paid is its last state), so it does not hold
                // the account; its buyers lose the downloads with the works, as when a creator closes.
                $unsettled = Order::query()
                    ->where('creator_id', $creatorId)
                    ->where(fn ($order) => $order
                        ->where('status', OrderStatus::Pending)
                        ->orWhere(fn ($paid) => $paid
                            ->where('status', OrderStatus::Paid)
                            ->where(fn ($toShip) => $toShip
                                ->whereHas('items', fn ($items) => $items->where('product_kind', ProductKind::Physical))
                                ->orWhereDoesntHave('items', fn ($items) => $items->where('product_kind', ProductKind::Digital)))));

                if ($unsettled->exists()) {
                    $validator->errors()->add('password', '未発送または支払い待ちの注文があるため、アカウントを削除できません。');
                }
            },
        ];
    }
}
