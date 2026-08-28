<?php

use Illuminate\Support\Facades\Route;
use Modules\Chat\Http\Controllers\ChatController;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('chat', [ChatController::class, 'index'])->name('chat.index');
    Route::post('chat/messages', [ChatController::class, 'stream'])->name('chat.stream');
    Route::get('chat/{conversation}', [ChatController::class, 'show'])->name('chat.show');
});
