<?php

use App\Http\Controllers\IndexController;
use App\Http\Controllers\LocalizationController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\TermsController;
use Illuminate\Support\Facades\Route;

Route::get('/', IndexController::class)->name('index');

Route::get('/privacy', PrivacyController::class)->name('privacy');
Route::get('/terms', TermsController::class)->name('terms');

Route::post('/locale/{locale}', LocalizationController::class)->name('locale');

// Chat is this application's home. The route name is kept because the auth module
// sends users here after login, registration and email verification; redirecting
// is what keeps those seven call sites working untouched. It deliberately carries
// only 'auth' -- gating the redirect more tightly than its destination would 403
// users who are allowed to chat.
Route::redirect('/dashboard', '/chat')->name('dashboard')->middleware('auth');
