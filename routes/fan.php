<?php

use App\Http\Controllers\Fan\CheckoutCompleteController;
use App\Http\Controllers\Fan\FeedController;
use App\Http\Controllers\Fan\SupportController;
use App\Http\Controllers\Fan\SupportingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('feed', FeedController::class)->name('feed');
    Route::get('supporting', SupportingController::class)->name('supporting.index');

    Route::post('/@{creator}/plans/{plan}/support', [SupportController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('supports.store');
    Route::get('supports/checkout/complete', CheckoutCompleteController::class)->name('supports.checkout.complete');
    Route::delete('supports/{support}', [SupportController::class, 'destroy'])
        ->middleware('throttle:10,1')
        ->name('supports.destroy');
});
