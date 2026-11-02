<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Http\Requests\Creator\UpdateShopSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShopSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $creator = $request->user()->creator;

        return Inertia::render('creator/shop', [
            'shop' => [
                'shipping_fee' => $creator->shipping_fee,
                'shop_notice' => $creator->shop_notice,
                'shop_contact_email' => $creator->shop_contact_email,
            ],
        ]);
    }

    public function update(UpdateShopSettingsRequest $request): RedirectResponse
    {
        $request->user()->creator->update([
            'shipping_fee' => $request->validated('shipping_fee'),
            'shop_notice' => $request->validated('shop_notice') ?: null,
            'shop_contact_email' => $request->validated('shop_contact_email') ?: null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'ショップ設定を保存しました。']);

        return to_route('creator.shop.edit');
    }
}
