<?php

use App\Http\Controllers\Fan\BillingPortalController;
use App\Http\Controllers\Fan\CartController;
use App\Http\Controllers\Fan\CartItemController;
use App\Http\Controllers\Fan\CheckoutCompleteController;
use App\Http\Controllers\Fan\CheckoutConfirmController;
use App\Http\Controllers\Fan\FeedController;
use App\Http\Controllers\Fan\FollowController;
use App\Http\Controllers\Fan\OrderCheckoutController;
use App\Http\Controllers\Fan\OrderController;
use App\Http\Controllers\Fan\PostLikeController;
use App\Http\Controllers\Fan\SupportController;
use App\Http\Controllers\Fan\SupportingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('feed', FeedController::class)->name('feed');
    Route::get('supporting', SupportingController::class)->name('supporting.index');

    Route::get('/@{creator}/plans/{plan}/confirm', CheckoutConfirmController::class)->name('supports.confirm');
    Route::post('/@{creator}/plans/{plan}/support', [SupportController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('supports.store');
    // Its own bucket: the success page may be reloaded while waiting, without eating into the support actions' limit.
    Route::get('supports/checkout/complete', CheckoutCompleteController::class)
        ->middleware('throttle:30,1,checkout-complete')
        ->name('supports.checkout.complete');
    Route::put('supports/{support}', [SupportController::class, 'update'])
        ->middleware('throttle:10,1')
        ->name('supports.update');
    Route::delete('supports/{support}', [SupportController::class, 'destroy'])
        ->middleware('throttle:10,1')
        ->name('supports.destroy');
    Route::post('supports/{support}/resume', [SupportController::class, 'resume'])
        ->middleware('throttle:10,1')
        ->name('supports.resume');
    Route::post('/@{creator}/posts/{post}/like', [PostLikeController::class, 'store'])
        ->whereNumber('post')
        ->middleware('throttle:60,1,post-likes')
        ->name('posts.like.store');
    Route::delete('/@{creator}/posts/{post}/like', [PostLikeController::class, 'destroy'])
        ->whereNumber('post')
        ->middleware('throttle:60,1,post-likes')
        ->name('posts.like.destroy');
    Route::post('/@{creator}/follow', [FollowController::class, 'store'])
        ->middleware('throttle:60,1,creator-follows')
        ->name('creators.follow.store');
    Route::delete('/@{creator}/follow', [FollowController::class, 'destroy'])
        ->middleware('throttle:60,1,creator-follows')
        ->name('creators.follow.destroy');

    Route::get('cart', [CartController::class, 'index'])->name('cart.index');
    Route::post('cart/items', [CartItemController::class, 'store'])->middleware('throttle:60,1,cart')->name('cart.items.store');
    Route::put('cart/items/{cartItem}', [CartItemController::class, 'update'])
        ->whereNumber('cartItem')
        ->middleware('throttle:60,1,cart')
        ->name('cart.items.update');
    Route::delete('cart/items/{cartItem}', [CartItemController::class, 'destroy'])
        ->whereNumber('cartItem')
        ->middleware('throttle:60,1,cart')
        ->name('cart.items.destroy');
    Route::get('cart/{creator}/confirm', [CartController::class, 'confirm'])->name('cart.confirm');
    Route::post('cart/{creator}/checkout', [OrderCheckoutController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('cart.checkout');
    // Its own bucket, like the support checkout's: the return page may be reloaded while waiting.
    Route::get('orders/checkout/complete', [OrderCheckoutController::class, 'complete'])
        ->middleware('throttle:30,1,order-checkout-complete')
        ->name('orders.checkout.complete');
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('orders.show');
    Route::post('billing/portal', BillingPortalController::class)
        ->middleware('throttle:10,1')
        ->name('billing.portal');
});
