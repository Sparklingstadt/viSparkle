<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PostModerationController;
use App\Http\Controllers\Admin\ProductModerationController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::patch('reports/{report}', [ReportController::class, 'update'])->name('reports.update');

    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::post('payments/{payment}/refund', [PaymentController::class, 'refund'])->name('payments.refund');

    Route::get('posts/{post}', [PostModerationController::class, 'show'])->name('posts.show');
    Route::patch('posts/{post}', [PostModerationController::class, 'update'])->name('posts.update');

    Route::post('orders/{order}/refund', [OrderController::class, 'refund'])->name('orders.refund');

    Route::get('products/{product}', [ProductModerationController::class, 'show'])->name('products.show');
    Route::put('products/{product}', [ProductModerationController::class, 'update'])->name('products.update');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update');
});
