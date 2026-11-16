<?php

use App\Http\Controllers\Billing\StripeWebhookController;
use App\Http\Controllers\Dev\FakeCheckoutController;
use Illuminate\Support\Facades\Route;

// The fake billing driver's stand-in for Stripe Checkout; never registered with the real driver or in production.
if (config('fanbox.billing.driver') === 'fake' && ! app()->isProduction()) {
    Route::middleware('auth')->group(function () {
        Route::get('dev/fake-checkout/{session}', [FakeCheckoutController::class, 'show'])->name('dev.fake-checkout.show');
        Route::post('dev/fake-checkout/{session}', [FakeCheckoutController::class, 'pay'])->name('dev.fake-checkout.pay');
    });
}

// Stripe's servers call this, so it exists for every driver and carries no session; the signature is the authentication.
Route::post('stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');
