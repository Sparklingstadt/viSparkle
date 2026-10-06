<?php

use App\Http\Controllers\Creator\CreatorRegistrationController;
use App\Http\Controllers\Creator\PlanController;
use App\Http\Controllers\Creator\ProfileController;
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
    });
});
