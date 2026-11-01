<?php

use App\Http\Controllers\Legal\AcceptTermsController;
use App\Http\Controllers\Public\CreatorPageController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\LegalController;
use App\Http\Controllers\Public\PostFileDownloadController;
use App\Http\Controllers\Public\PostPageController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::controller(LegalController::class)->prefix('legal')->name('legal.')->group(function () {
    Route::get('tokushoho', 'tokushoho')->name('tokushoho');
    Route::get('terms', 'terms')->name('terms');
    Route::get('privacy', 'privacy')->name('privacy');
});

Route::middleware('auth')->controller(AcceptTermsController::class)->group(function () {
    Route::get('legal/accept', 'show')->name('legal.accept');
    Route::post('legal/accept', 'store')->name('legal.accept.store');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', '/feed')->name('dashboard');
    Route::post('reports', [ReportController::class, 'store'])->middleware('throttle:10,60')->name('reports.store');
});

require __DIR__.'/settings.php';
require __DIR__.'/creator.php';
require __DIR__.'/fan.php';
require __DIR__.'/billing.php';

Route::get('/@{creator}', CreatorPageController::class)->name('creators.show');
Route::get('/@{creator}/posts/{post}', PostPageController::class)->whereNumber('post')->name('posts.show');
Route::get('/@{creator}/posts/{post}/files/{file}', PostFileDownloadController::class)
    ->whereNumber(['post', 'file'])
    ->middleware('throttle:120,1')
    ->name('posts.files.show');
