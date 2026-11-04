<?php

namespace App\Http\Controllers\Fan;

use App\Http\Controllers\Controller;
use App\Models\Creator;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Both actions are idempotent, so a double click or a resend never flips the state back.
 */
class ProductLikeController extends Controller
{
    public function store(Request $request, Creator $creator, int $product): RedirectResponse
    {
        abort_if($creator->user_id === $request->user()->id, 403);

        $this->likeable($creator, $product)->like($request->user());

        return back();
    }

    public function destroy(Request $request, Creator $creator, int $product): RedirectResponse
    {
        $this->likeable($creator, $product)->unlike($request->user());

        return back();
    }

    /**
     * Not route-bound: an unlisted product is as good as missing, and so is another creator's.
     */
    private function likeable(Creator $creator, int $product): Product
    {
        abort_unless($creator->isShopVisible(), 404);

        return Product::query()->listed()->where('creator_id', $creator->id)->findOrFail($product);
    }
}
