<?php

use App\Http\Controllers\Public\CreatorPageController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\PostPageController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/creator.php';

Route::get('/@{creator}', CreatorPageController::class)->name('creators.show');
Route::get('/@{creator}/posts/{post}', PostPageController::class)->whereNumber('post')->name('posts.show');
