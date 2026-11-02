<?php

namespace App\Services\Shop;

use App\Models\CartItem;
use App\Models\Creator;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The buyer's cart. Rows hold only a product and a quantity; names and prices are read from the product until
 * an order copies them (Orders::reserve).
 *
 * @phpstan-type Group array{creator: Creator, items: Collection<int, CartItem>, subtotal: int, shipping_fee: int, total: int, purchasable: bool}
 */
class Cart
{
    /**
     * Put the quantity in the cart, on top of what is there already.
     *
     * @throws ValidationException (key `cart`) when the product cannot be bought, is the user's own, or the total exceeds the stock
     */
    public function add(User $user, Product $product, int $quantity): void
    {
        $product->loadMissing('creator.user');
        $creator = $product->creator;

        if ($creator->user_id === $user->id) {
            throw ValidationException::withMessages(['cart' => '自分の商品は購入できません。']);
        }

        if (! $product->isListed() || ! $creator->isShopVisible()) {
            throw ValidationException::withMessages(['cart' => '現在購入できません']);
        }

        DB::transaction(function () use ($user, $product, $quantity) {
            $row = CartItem::query()->where('user_id', $user->id)->where('product_id', $product->id)->lockForUpdate()->first();
            $total = ($row->quantity ?? 0) + $quantity;

            if ($quantity < 1 || $total > $product->stock) {
                throw ValidationException::withMessages(['cart' => '在庫が足りません']);
            }

            if ($row === null) {
                CartItem::query()->firstOrCreate(
                    ['user_id' => $user->id, 'product_id' => $product->id],
                    ['quantity' => $total],
                );

                return;
            }

            $row->update(['quantity' => $total]);
        });
    }

    /**
     * Set a row's quantity. Raising it is held to the stock; the product itself may have gone unlisted since.
     *
     * @throws ValidationException (key `cart`)
     */
    public function setQuantity(CartItem $item, int $quantity): void
    {
        $product = $item->product;

        if ($quantity < 1 || $quantity > $product->stock) {
            throw ValidationException::withMessages(['cart' => '在庫が足りません']);
        }

        $item->update(['quantity' => $quantity]);
    }

    /**
     * The cart grouped by creator, in the order each creator first appeared.
     *
     * @return list<Group>
     */
    public function groups(User $user): array
    {
        $items = CartItem::query()
            ->where('user_id', $user->id)
            ->with(['product.images', 'product.creator.user'])
            ->orderBy('id')
            ->get();

        $groups = $items->groupBy(fn (CartItem $item) => $item->product->creator_id)
            ->map(fn ($rows) => $this->build($user, $rows->values()));

        return array_values($groups->all());
    }

    /**
     * The user's cart rows of one creator as a group, or null when there are none.
     *
     * @return Group|null
     */
    public function group(User $user, Creator $creator): ?array
    {
        $items = CartItem::query()
            ->where('user_id', $user->id)
            ->whereHas('product', fn ($query) => $query->withTrashed()->where('creator_id', $creator->id))
            ->with(['product.images', 'product.creator.user'])
            ->orderBy('id')
            ->get();

        return $items->isEmpty() ? null : $this->build($user, $items);
    }

    /**
     * @param  Collection<int, CartItem>  $items
     * @return Group
     */
    private function build(User $user, Collection $items): array
    {
        $creator = $items->first()->product->creator;
        $subtotal = $items->sum(fn (CartItem $item) => $item->product->price * $item->quantity);

        return [
            'creator' => $creator,
            'items' => $items,
            'subtotal' => $subtotal,
            'shipping_fee' => $creator->shipping_fee,
            'total' => $subtotal + $creator->shipping_fee,
            'purchasable' => $creator->canSell()
                && $creator->user_id !== $user->id
                && $items->every(fn (CartItem $item) => $item->product->isListed() && $item->product->stock >= $item->quantity),
        ];
    }
}
