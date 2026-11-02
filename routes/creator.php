<?php

use App\Http\Controllers\Creator\ClosureController;
use App\Http\Controllers\Creator\CreatorRegistrationController;
use App\Http\Controllers\Creator\EarningsController;
use App\Http\Controllers\Creator\OrderController;
use App\Http\Controllers\Creator\PlanController;
use App\Http\Controllers\Creator\PostController;
use App\Http\Controllers\Creator\PostFileController;
use App\Http\Controllers\Creator\ProductController;
use App\Http\Controllers\Creator\ProductImageController;
use App\Http\Controllers\Creator\ProfileController;
use App\Http\Controllers\Creator\ShopSettingsController;
use App\Http\Controllers\Creator\SupporterController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('creator')->name('creator.')->group(function () {
    Route::get('register', [CreatorRegistrationController::class, 'create'])->name('register');
    Route::post('register', [CreatorRegistrationController::class, 'store'])->name('register.store');

    Route::middleware('creator')->group(function () {
        Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::post('profile', [ProfileController::class, 'update'])->name('profile.update');

        Route::post('closure', [ClosureController::class, 'store'])->name('closure.store');
        Route::delete('closure', [ClosureController::class, 'destroy'])->name('closure.destroy');

        Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
        Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
        Route::post('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
        Route::delete('plans/{plan}', [PlanController::class, 'destroy'])->name('plans.destroy');

        Route::get('supporters', [SupporterController::class, 'index'])->name('supporters.index');

        Route::get('earnings', [EarningsController::class, 'index'])->name('earnings.index');
        Route::post('earnings/onboarding', [EarningsController::class, 'onboarding'])->name('earnings.onboarding');
        Route::get('earnings/onboarding/return', [EarningsController::class, 'onboardingReturn'])->name('earnings.onboarding.return');
        Route::post('earnings/dashboard', [EarningsController::class, 'dashboard'])->name('earnings.dashboard');

        Route::get('posts', [PostController::class, 'index'])->name('posts.index');
        Route::post('posts', [PostController::class, 'store'])->name('posts.store');
        Route::get('posts/{post}/edit', [PostController::class, 'edit'])->name('posts.edit');
        Route::put('posts/{post}', [PostController::class, 'update'])->name('posts.update');
        Route::delete('posts/{post}', [PostController::class, 'destroy'])->name('posts.destroy');

        Route::post('posts/{post}/files', [PostFileController::class, 'store'])
            ->middleware('throttle:60,1')
            ->name('posts.files.store');
        Route::delete('posts/{post}/files/{file}', [PostFileController::class, 'destroy'])->name('posts.files.destroy');

        Route::get('shop', [ShopSettingsController::class, 'edit'])->name('shop.edit');
        Route::put('shop', [ShopSettingsController::class, 'update'])->name('shop.update');

        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::get('products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('products', [ProductController::class, 'store'])->name('products.store');
        Route::get('products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');

        Route::post('products/{product}/images', [ProductImageController::class, 'store'])
            ->middleware('throttle:60,1')
            ->name('products.images.store');
        Route::put('products/{product}/images', [ProductImageController::class, 'reorder'])->name('products.images.reorder');
        Route::delete('products/{product}/images/{image}', [ProductImageController::class, 'destroy'])->name('products.images.destroy');

        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('orders.show');
        Route::post('orders/{order}/ship', [OrderController::class, 'ship'])
            ->whereNumber('order')
            ->middleware('throttle:10,1')
            ->name('orders.ship');
        Route::post('orders/{order}/refund', [OrderController::class, 'refund'])
            ->whereNumber('order')
            ->middleware('throttle:10,1')
            ->name('orders.refund');
    });
});
