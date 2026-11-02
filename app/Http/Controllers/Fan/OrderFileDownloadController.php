<?php

namespace App\Http\Controllers\Fan;

use App\Enums\ProductKind;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ProductFile;
use App\Services\PrivateFileUrls;
use App\Services\Shop\DigitalAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Stable link for a purchased work's file: re-checks access on every click (§6.5) and redirects to a freshly signed
 * URL. No bytes pass through the app.
 */
class OrderFileDownloadController extends Controller
{
    public function __invoke(Request $request, Order $order, ProductFile $file, DigitalAccess $access, PrivateFileUrls $urls): RedirectResponse
    {
        $buyer = $request->user();

        abort_unless($buyer->can('view', $order), 404);
        abort_unless(
            $order->items()->where('product_id', $file->product_id)->where('product_kind', ProductKind::Digital)->exists(),
            404,
        );
        abort_unless($access->refusal($buyer, $order, $file->product) === null, 404);

        return redirect()->away($urls->download($file->path, $file->original_name));
    }
}
