<?php

namespace App\Http\Controllers\Admin;

use App\Billing\BillingException;
use App\Enums\OrderCancelReason;
use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\Order;
use App\Services\Shop\Orders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class OrderController extends Controller
{
    public function refund(Request $request, Order $order, Orders $orders): RedirectResponse
    {
        try {
            $orders->refund($order, OrderCancelReason::Admin);
        } catch (BillingException $e) {
            Log::error('billing.ops_failed', ['operation' => 'order_refund', 'order_id' => $order->id, 'message' => $e->getMessage()]);
            Inertia::flash('toast', ['type' => 'error', 'message' => '決済サービスに接続できませんでした。時間をおいてお試しください。']);

            return back();
        }

        AdminAction::record($request->user(), 'order.refunded', $order, null);

        Inertia::flash('toast', ['type' => 'success', 'message' => '注文を返金しました。']);

        return back();
    }
}
