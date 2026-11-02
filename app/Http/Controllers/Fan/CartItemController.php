<?php

namespace App\Http\Controllers\Fan;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Product;
use App\Services\Shop\Cart;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CartItemController extends Controller
{
    public function __construct(private readonly Cart $cart) {}

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:99999'],
        ]);

        // A product that is gone is "not available", not a 404 the buyer cannot make sense of.
        $product = Product::query()->whereKey($request->integer('product_id'))->first();

        if ($product === null) {
            throw ValidationException::withMessages(['cart' => '現在購入できません']);
        }

        $this->cart->add($request->user(), $product, $request->integer('quantity'));

        return back();
    }

    public function update(Request $request, CartItem $cartItem): RedirectResponse
    {
        $this->authorizeOwner($request, $cartItem);

        $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:99999']]);
        $this->cart->setQuantity($cartItem, $request->integer('quantity'));

        return back();
    }

    public function destroy(Request $request, CartItem $cartItem): RedirectResponse
    {
        $this->authorizeOwner($request, $cartItem);

        $cartItem->delete();

        return back();
    }

    private function authorizeOwner(Request $request, CartItem $cartItem): void
    {
        abort_unless($cartItem->user_id === $request->user()->id, 404);
    }
}
