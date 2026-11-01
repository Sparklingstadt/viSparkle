<?php

use App\Http\Controllers\Dev\FakeCheckoutController;
use Illuminate\Support\Facades\Route;

// The fake billing driver's stand-in for Stripe Checkout; never registered with the real driver.
if (config('fanbox.billing.driver') === 'fake') {
    Route::middleware('auth')->group(function () {
        Route::get('dev/fake-checkout/{session}', [FakeCheckoutController::class, 'show'])->name('dev.fake-checkout.show');
        Route::post('dev/fake-checkout/{session}', [FakeCheckoutController::class, 'pay'])->name('dev.fake-checkout.pay');
    });
}
