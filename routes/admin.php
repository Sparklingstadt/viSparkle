<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ReportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::patch('reports/{report}', [ReportController::class, 'update'])->name('reports.update');
});
