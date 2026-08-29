<?php

use Illuminate\Support\Facades\Route;
use Modules\Chat\Http\Controllers\ChatController;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('chat', [ChatController::class, 'index'])->name('chat.index');
    Route::post('chat/messages', [ChatController::class, 'stream'])->name('chat.stream');
    // Declared before chat/{conversation} so "place" is not read as an id.
    Route::post('chat/place', [ChatController::class, 'place'])->name('chat.place');
    Route::get('chat/{conversation}/messages', [ChatController::class, 'messages'])->name('chat.messages');
    Route::get('chat/{conversation}', [ChatController::class, 'show'])->name('chat.show');
});
