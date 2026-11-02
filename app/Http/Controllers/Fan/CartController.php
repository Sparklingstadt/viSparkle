<?php

namespace App\Http\Controllers\Fan;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Creator;
use App\Services\Shop\Cart;
use App\Services\Shop\OrderCheckout;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CartController extends Controller
{
    public function __construct(
        private readonly Cart $cart,
        private readonly OrderCheckout $checkout,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        // Coming back from Stripe's page without paying: give the held stock back before showing the cart.
        $canceled = $request->query('canceled');

        if (is_string($canceled) && ctype_digit($canceled)) {
            $this->checkout->abandon($user, (int) $canceled);
        }

        return Inertia::render('fan/cart', [
            'groups' => array_map(fn (array $group) => $this->present($group), $this->cart->groups($user)),
        ]);
    }

    /**
     * The last screen before payment, with the terms the 特定商取引法 asks for.
     */
    public function confirm(Request $request, Creator $creator): Response|RedirectResponse
    {
        $group = $this->cart->group($request->user(), $creator);

        if ($group === null || ! $group['purchasable']) {
            if ($group !== null) {
                Inertia::flash('toast', ['type' => 'error', 'message' => $this->cart->refusal($request->user(), $group)]);
            }

            return to_route('cart.index');
        }

        $digital = $group['items']->filter(fn (CartItem $item) => $item->variant->product->isDigital());

        return Inertia::render('fan/cart-confirm', [
            'creator' => $creator->toSummary(),
            'group' => $this->present($group),
            'has_digital' => $digital->isNotEmpty(),
            'has_physical' => $digital->count() < $group['items']->count(),
        ]);
    }

    /**
     * @param  array{creator: Creator, items: Collection<int, CartItem>, subtotal: int, shipping_fee: int, total: int, purchasable: bool}  $group
     * @return array<string, mixed>
     */
    private function present(array $group): array
    {
        return [
            'creator' => $group['creator']->toSummary(),
            'items' => $group['items']->map(fn (CartItem $item) => [
                'id' => $item->id,
                'product_id' => $item->variant->product_id,
                'kind' => $item->variant->product->kind->value,
                'name' => $item->variant->label(),
                'price' => $item->variant->product->price,
                'quantity' => $item->quantity,
                'stock' => $item->variant->stock,
                'listed' => $item->isAvailable(),
                'cover_url' => $item->variant->product->coverUrl(),
            ])->values(),
            'subtotal' => $group['subtotal'],
            'shipping_fee' => $group['shipping_fee'],
            'total' => $group['total'],
            'purchasable' => $group['purchasable'],
        ];
    }
}
