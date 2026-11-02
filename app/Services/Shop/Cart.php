<?php

namespace App\Services\Shop;

use App\Models\CartItem;
use App\Models\Creator;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The buyer's cart. Rows hold only a variant (a kind of a product) and a quantity; names and prices are read from the
 * product until an order copies them (Orders::reserve). A digital work is always one, has no stock, and cannot be
 * bought twice; shipping is charged only for goods.
 *
 * @phpstan-type Group array{creator: Creator, items: Collection<int, CartItem>, subtotal: int, shipping_fee: int, total: int, purchasable: bool}
 */
class Cart
{
    public function __construct(private readonly DigitalAccess $access) {}

    /**
     * Put the quantity in the cart, on top of what is there already. A digital work goes in once, as one.
     *
     * @throws ValidationException (key `cart`) when the product cannot be bought, is the user's own, or the total exceeds the stock
     */
    public function add(User $user, ProductVariant $variant, int $quantity): void
    {
        $variant->loadMissing('product.creator.user');
        $product = $variant->product;
        $creator = $product->creator;

        if ($creator->user_id === $user->id) {
            throw ValidationException::withMessages(['cart' => '自分の商品は購入できません。']);
        }

        if ($variant->trashed() || ! $product->isListed() || ! $creator->isShopVisible()) {
            throw ValidationException::withMessages(['cart' => '現在購入できません']);
        }

        if ($product->isDigital()) {
            $this->addWork($user, $variant);

            return;
        }

        DB::transaction(function () use ($user, $variant, $quantity) {
            $row = CartItem::query()->where('user_id', $user->id)->where('product_variant_id', $variant->id)->lockForUpdate()->first();
            $total = ($row->quantity ?? 0) + $quantity;

            if ($quantity < 1 || $total > $variant->stock) {
                throw ValidationException::withMessages(['cart' => '在庫が足りません']);
            }

            if ($row === null) {
                CartItem::query()->firstOrCreate(
                    ['user_id' => $user->id, 'product_variant_id' => $variant->id],
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
        if ($item->variant->product->isDigital()) {
            if ($quantity !== 1) {
                throw ValidationException::withMessages(['cart' => 'デジタル作品は1点ずつの購入です。']);
            }

            return;
        }

        if ($quantity < 1 || $quantity > $item->variant->stock) {
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
            ->with(['variant.product.images', 'variant.product.files', 'variant.product.creator.user'])
            ->orderBy('id')
            ->get();

        $groups = $items->groupBy(fn (CartItem $item) => $item->variant->product->creator_id)
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
            ->whereHas('variant.product', fn ($query) => $query->withTrashed()->where('creator_id', $creator->id))
            ->with(['variant.product.images', 'variant.product.files', 'variant.product.creator.user'])
            ->orderBy('id')
            ->get();

        return $items->isEmpty() ? null : $this->build($user, $items);
    }

    /**
     * Why a group cannot be bought (§8): 「購入済みの作品です」 when it holds a work the user already bought, 「在庫が足りません」
     * when only the stock falls short, otherwise 「現在購入できません」.
     *
     * @param  Group  $group
     */
    public function refusal(User $user, array $group): string
    {
        $creator = $group['creator'];
        $items = $group['items'];

        if ($items->contains(fn (CartItem $item) => $this->isPurchasedWork($user, $item))) {
            return '購入済みの作品です';
        }

        $onlyShort = $creator->canSell()
            && $creator->user_id !== $user->id
            && $items->every(fn (CartItem $item) => $item->isAvailable())
            && $items->every(fn (CartItem $item) => ! $item->variant->product->isDigital() || $item->variant->product->isPurchasable())
            && $items->contains(fn (CartItem $item) => ! $item->variant->product->isDigital() && $item->variant->stock < $item->quantity);

        return $onlyShort ? '在庫が足りません' : '現在購入できません';
    }

    /**
     * @param  Collection<int, CartItem>  $items
     * @return Group
     */
    private function build(User $user, Collection $items): array
    {
        $creator = $items->first()->variant->product->creator;
        $subtotal = $items->sum(fn (CartItem $item) => $item->variant->product->price * $item->quantity);
        $shippingFee = $items->contains(fn (CartItem $item) => ! $item->variant->product->isDigital()) ? $creator->shipping_fee : 0;

        return [
            'creator' => $creator,
            'items' => $items,
            'subtotal' => $subtotal,
            'shipping_fee' => $shippingFee,
            'total' => $subtotal + $shippingFee,
            'purchasable' => $creator->canSell()
                && $creator->user_id !== $user->id
                && $items->every(fn (CartItem $item) => $item->isAvailable() && $this->hasEnough($user, $item)),
        ];
    }

    /**
     * Goods need the stock for the quantity; a digital work needs a file, a quantity of one and not to be bought already.
     */
    private function hasEnough(User $user, CartItem $item): bool
    {
        $product = $item->variant->product;

        if (! $product->isDigital()) {
            return $item->variant->stock >= $item->quantity;
        }

        return $product->isPurchasable() && $item->quantity === 1 && ! $this->access->hasPurchased($user, $product);
    }

    private function isPurchasedWork(User $user, CartItem $item): bool
    {
        return $item->variant->product->isDigital() && $this->access->hasPurchased($user, $item->variant->product);
    }

    /**
     * @throws ValidationException (key `cart`)
     */
    private function addWork(User $user, ProductVariant $variant): void
    {
        if ($this->access->hasPurchased($user, $variant->product)) {
            throw ValidationException::withMessages(['cart' => '購入済みの作品です']);
        }

        if (! $variant->product->isPurchasable()) {
            throw ValidationException::withMessages(['cart' => '現在購入できません']);
        }

        CartItem::query()->firstOrCreate(
            ['user_id' => $user->id, 'product_variant_id' => $variant->id],
            ['quantity' => 1],
        );
    }
}
