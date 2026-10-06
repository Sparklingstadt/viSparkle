<?php

use App\Http\Controllers\Creator\CreatorRegistrationController;
use App\Http\Controllers\Creator\PlanController;
use App\Http\Controllers\Creator\PostController;
use App\Http\Controllers\Creator\PostFileController;
use App\Http\Controllers\Creator\ProfileController;
use App\Http\Controllers\Creator\SupporterController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('creator')->name('creator.')->group(function () {
    Route::get('register', [CreatorRegistrationController::class, 'create'])->name('register');
    Route::post('register', [CreatorRegistrationController::class, 'store'])->name('register.store');

    Route::middleware('creator')->group(function () {
        Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::post('profile', [ProfileController::class, 'update'])->name('profile.update');

        Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
        Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
        Route::post('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
        Route::delete('plans/{plan}', [PlanController::class, 'destroy'])->name('plans.destroy');

        Route::get('supporters', [SupporterController::class, 'index'])->name('supporters.index');

        Route::get('posts', [PostController::class, 'index'])->name('posts.index');
        Route::post('posts', [PostController::class, 'store'])->name('posts.store');
        Route::get('posts/{post}/edit', [PostController::class, 'edit'])->name('posts.edit');
        Route::put('posts/{post}', [PostController::class, 'update'])->name('posts.update');
        Route::delete('posts/{post}', [PostController::class, 'destroy'])->name('posts.destroy');

        Route::post('posts/{post}/files', [PostFileController::class, 'store'])
            ->middleware('throttle:60,1')
            ->name('posts.files.store');
        Route::delete('posts/{post}/files/{file}', [PostFileController::class, 'destroy'])->name('posts.files.destroy');
    });
});
