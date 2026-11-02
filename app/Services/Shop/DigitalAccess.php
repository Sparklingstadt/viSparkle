<?php

namespace App\Services\Shop;

use App\Enums\OrderStatus;
use App\Enums\ProductKind;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;

/**
 * Who may download a digital work (§6.5). Nothing is stored: access follows the buyer's orders and the work as they
 * are now, so every route that changes an order (a refund, a webhook, a cancellation) changes access with it.
 */
class DigitalAccess
{
    private const PURCHASED = [OrderStatus::Paid, OrderStatus::Shipped];

    /**
     * Whether the user has a paid or shipped order holding the work.
     */
    public function hasPurchased(User $user, Product $product): bool
    {
        return OrderItem::query()
            ->where('product_id', $product->id)
            ->where('product_kind', ProductKind::Digital)
            ->whereHas('order', fn ($order) => $order->where('user_id', $user->id)->whereIn('status', self::PURCHASED))
            ->exists();
    }

    /**
     * Why the buyer cannot download the work of this order now, or null when they can. The caller has already checked
     * that the order is the buyer's and holds the work.
     */
    public function refusal(User $buyer, Order $order, Product $product): ?string
    {
        if ($buyer->isSuspended()) {
            return '現在ダウンロードできません';
        }

        $statusReason = match ($order->status) {
            OrderStatus::Pending => '支払いの確認後にダウンロードできます',
            OrderStatus::Canceled => '取り消された注文のためダウンロードできません',
            OrderStatus::Refunded => '返金済みのためダウンロードできません',
            OrderStatus::Paid, OrderStatus::Shipped => null,
        };

        if ($statusReason !== null) {
            return $statusReason;
        }

        if ($order->disputed_at !== null) {
            return 'お支払いに問題があったため、ダウンロードできません';
        }

        if (! $product->isListed() || ! $product->creator->isShopVisible()) {
            return 'この作品は現在公開されていないため、ダウンロードできません';
        }

        return null;
    }
}
