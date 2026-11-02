<?php

namespace App\Http\Controllers\Creator;

use App\Billing\BillingException;
use App\Billing\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Models\Creator;
use App\Services\Billing\AccountSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The creator's payout account (Stripe Connect) and a rough view of monthly earnings.
 */
class EarningsController extends Controller
{
    private const MONTHS = 12;

    public function index(Request $request): Response
    {
        $creator = $request->user()->creator;

        return Inertia::render('creator/earnings', [
            'account' => ['status' => $this->accountStatus($creator)],
            'feePercent' => (int) config('fanbox.billing.platform_fee_percent'),
            'activeSupporters' => $creator->supports()->active()->count(),
            'months' => $this->months($creator),
            'shop_months' => $this->shopMonths($creator),
        ]);
    }

    public function onboarding(Request $request, PaymentGateway $gateway): SymfonyResponse
    {
        $creator = $request->user()->creator;

        try {
            if ($creator->stripe_account_id === null) {
                $creator->forceFill(['stripe_account_id' => $gateway->createConnectAccount($creator)])->save();
            }

            return Inertia::location($gateway->createAccountOnboardingLink(
                (string) $creator->stripe_account_id,
                route('creator.earnings.index'),
                route('creator.earnings.onboarding.return'),
            ));
        } catch (BillingException $e) {
            report($e);
            Inertia::flash('toast', ['type' => 'error', 'message' => '決済サービスに接続できませんでした。時間をおいてお試しください。']);

            return back();
        }
    }

    public function onboardingReturn(Request $request, AccountSync $sync): RedirectResponse
    {
        $creator = $request->user()->creator;

        if ($creator->stripe_account_id !== null) {
            try {
                $creator = $sync->sync($creator->stripe_account_id) ?? $creator;
            } catch (BillingException $e) {
                report($e);
                Inertia::flash('toast', ['type' => 'error', 'message' => '決済サービスに接続できませんでした。時間をおいてお試しください。']);

                return to_route('creator.earnings.index');
            }
        }

        Inertia::flash('toast', $creator->canAcceptSupports()
            ? ['type' => 'success', 'message' => '口座の登録が完了しました。']
            : ['type' => 'info', 'message' => '登録はまだ完了していません。続きから再開できます。']);

        return to_route('creator.earnings.index');
    }

    public function dashboard(Request $request, PaymentGateway $gateway): SymfonyResponse
    {
        $accountId = $request->user()->creator->stripe_account_id;

        abort_if($accountId === null, 404);

        try {
            return Inertia::location($gateway->createDashboardLoginLink($accountId));
        } catch (BillingException $e) {
            report($e);
            Inertia::flash('toast', ['type' => 'error', 'message' => '決済サービスに接続できませんでした。時間をおいてお試しください。']);

            return back();
        }
    }

    private function accountStatus(Creator $creator): string
    {
        if ($creator->stripe_account_id === null) {
            return 'none';
        }

        return $creator->canAcceptSupports() ? 'ready' : 'incomplete';
    }

    /**
     * The last twelve Asia/Tokyo calendar months ending with the current one, newest first, zero-filled.
     * Sales are net of refunds; fees are estimated per payment (floor) on the net amount, like Stripe's per-invoice
     * application fee (a full refund also refunds the fee).
     *
     * @return list<array{month: string, gross: int, fee: int, net: int}>
     */
    private function months(Creator $creator): array
    {
        $tz = 'Asia/Tokyo';
        $thisMonth = Carbon::now($tz)->startOfMonth();
        $windowStart = $thisMonth->copy()->subMonthsNoOverflow(self::MONTHS - 1);

        $keys = [];
        for ($i = 0; $i < self::MONTHS; $i++) {
            $keys[] = $thisMonth->copy()->subMonthsNoOverflow($i)->format('Y-m');
        }

        $payments = $creator->payments()
            ->where('paid_at', '>=', $windowStart)
            ->where('paid_at', '<', $thisMonth->copy()->addMonthNoOverflow())
            ->get(['amount', 'refunded_amount', 'fee_percent', 'paid_at']);

        $gross = [];
        $fee = [];
        foreach ($payments as $payment) {
            $key = $payment->paid_at->copy()->setTimezone($tz)->format('Y-m');
            $amount = $payment->netAmount();
            $gross[$key] = ($gross[$key] ?? 0) + $amount;
            $fee[$key] = ($fee[$key] ?? 0) + intdiv($amount * $payment->fee_percent, 100);
        }

        return array_map(fn (string $key) => [
            'month' => $key,
            'gross' => $gross[$key] ?? 0,
            'fee' => $fee[$key] ?? 0,
            'net' => ($gross[$key] ?? 0) - ($fee[$key] ?? 0),
        ], $keys);
    }

    /**
     * Goods sales for the same twelve months, by the month of payment. Sales are order totals; the fee is estimated
     * per order (floor) on the total, and is 0 for a fully refunded order.
     *
     * @return list<array{month: string, orders: int, sales: int, refunded: int, net: int}>
     */
    private function shopMonths(Creator $creator): array
    {
        $tz = 'Asia/Tokyo';
        $thisMonth = Carbon::now($tz)->startOfMonth();
        $windowStart = $thisMonth->copy()->subMonthsNoOverflow(self::MONTHS - 1);

        $keys = [];
        for ($i = 0; $i < self::MONTHS; $i++) {
            $keys[] = $thisMonth->copy()->subMonthsNoOverflow($i)->format('Y-m');
        }

        $orders = $creator->orders()
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $windowStart)
            ->where('paid_at', '<', $thisMonth->copy()->addMonthNoOverflow())
            ->get(['total', 'refunded_amount', 'fee_percent', 'paid_at']);

        $count = [];
        $sales = [];
        $refunded = [];
        $net = [];
        foreach ($orders as $order) {
            $key = $order->paid_at->copy()->setTimezone($tz)->format('Y-m');
            $fee = $order->refunded_amount >= $order->total ? 0 : intdiv($order->total * $order->fee_percent, 100);
            $count[$key] = ($count[$key] ?? 0) + 1;
            $sales[$key] = ($sales[$key] ?? 0) + $order->total;
            $refunded[$key] = ($refunded[$key] ?? 0) + $order->refunded_amount;
            $net[$key] = ($net[$key] ?? 0) + $order->total - $order->refunded_amount - $fee;
        }

        return array_map(fn (string $key) => [
            'month' => $key,
            'orders' => $count[$key] ?? 0,
            'sales' => $sales[$key] ?? 0,
            'refunded' => $refunded[$key] ?? 0,
            'net' => $net[$key] ?? 0,
        ], $keys);
    }
}
