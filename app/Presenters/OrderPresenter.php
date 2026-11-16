<?php

namespace App\Presenters;

use App\Enums\OrderCancelReason;
use App\Models\Order;
use App\Models\OrderItem;
use Closure;

/**
 * Builds the order props shared by the buyer's, the creator's and the admin's pages; each page adds its own keys.
 * Expects `items` eager loaded.
 */
class OrderPresenter
{
    /**
     * A row of an order list.
     *
     * @return array<string, mixed>
     */
    public function summary(Order $order): array
    {
        return [
            'id' => $order->id,
            'status' => $order->status->value,
            'status_label' => $order->statusLabel(),
            'total' => $order->total,
            'created_at' => $order->created_at,
            'items' => $order->items->map(fn (OrderItem $item) => [
                'id' => $item->id,
                'product_name' => $item->product_name,
                'quantity' => $item->quantity,
            ])->values(),
        ];
    }

    /**
     * The order page. $itemExtra adds keys to each item (the buyer's downloads).
     *
     * @param  (Closure(OrderItem): array<string, mixed>)|null  $itemExtra
     * @return array<string, mixed>
     */
    public function detail(Order $order, ?Closure $itemExtra = null): array
    {
        return [
            'id' => $order->id,
            'status' => $order->status->value,
            'status_label' => $order->statusLabel(),
            'items' => $order->items->map(fn (OrderItem $item) => [
                'id' => $item->id,
                'product_name' => $item->product_name,
                'product_kind' => $item->product_kind->value,
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                ...($itemExtra === null ? [] : $itemExtra($item)),
            ])->values(),
            'has_physical' => $order->needsShipping(),
            'subtotal' => $order->subtotal,
            'shipping_fee' => $order->shipping_fee,
            'support_amount' => $order->support_amount,
            'total' => $order->total,
            'shipping_name' => $order->shipping_name,
            'shipping_postal_code' => $order->shipping_postal_code,
            'shipping_address' => $order->shipping_address,
            'shipping_phone' => $order->shipping_phone,
            'tracking_number' => $order->tracking_number,
            'refunded_amount' => $order->refunded_amount,
            'created_at' => $order->created_at,
            'paid_at' => $order->paid_at,
            'shipped_at' => $order->shipped_at,
        ];
    }

    /**
     * A row of the admin's order list. Expects `creator` and `user` eager loaded too.
     *
     * @return array<string, mixed>
     */
    public function adminRow(Order $order): array
    {
        return [
            'id' => $order->id,
            'paid_at' => $order->paid_at,
            'creator' => $order->creator === null ? null : ['name' => $order->creator->display_name, 'slug' => $order->creator->slug],
            'buyer' => $order->user === null ? null : ['name' => $order->user->name, 'email' => $order->user->email],
            'total' => $order->total,
            'has_digital' => $order->hasDigital(),
            'has_physical' => $order->needsShipping(),
            'support_amount' => $order->support_amount,
            'status' => $order->status->value,
            'status_label' => $order->statusLabel(),
            'refundable' => $order->isRefundableBy(OrderCancelReason::Admin),
        ];
    }
}
