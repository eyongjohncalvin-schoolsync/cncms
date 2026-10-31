<?php

declare(strict_types=1);

use App\Http\Controllers\SiteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public website (cncms.tech)
|--------------------------------------------------------------------------
|
| Open to everyone, signed in or not. Home redirects signed-in staff to
| their dashboard; the other pages stay readable for them too.
|
*/
Route::get('/', [SiteController::class, 'home'])->name('site.home');
Route::get('about', [SiteController::class, 'about'])->name('site.about');
Route::get('contact', [SiteController::class, 'contact'])->name('site.contact');
Route::get('privacy', [SiteController::class, 'privacy'])->name('site.privacy');
Route::get('terms', [SiteController::class, 'terms'])->name('site.terms');
Route::get('language/{locale}', [SiteController::class, 'language'])->whereIn('locale', ['en', 'fr'])->name('site.language');
