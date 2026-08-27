<?php

use Modules\Chat\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function () {
    Route::get('chat', [ChatController::class, 'index'])->name('chat.index');
});
