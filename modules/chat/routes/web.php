<?php

use Illuminate\Support\Facades\Route;
use Modules\Chat\Http\Controllers\ChatController;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('chat', [ChatController::class, 'index'])->name('chat.index');
    Route::post('chat/messages', [ChatController::class, 'stream'])
        ->middleware('throttle:'.config('chat.throttle.stream', '30,1'))
        ->name('chat.stream');
    // Declared before chat/{conversation} so "place" is not read as an id.
    Route::post('chat/place', [ChatController::class, 'place'])
        ->middleware('throttle:'.config('chat.throttle.map', '60,1'))
        ->name('chat.place');
    // Filtering before a conversation exists: the landing page already shows
    // results, so its filters have to search something.
    Route::post('chat/property-search', [ChatController::class, 'propertySearch'])
        ->middleware('throttle:'.config('chat.throttle.map', '60,1'))
        ->name('chat.property-search');
    Route::post('chat/nearby', [ChatController::class, 'nearby'])
        ->middleware('throttle:'.config('chat.throttle.map', '60,1'))
        ->name('chat.nearby');
    Route::patch('chat/{conversation}/onboarding', [ChatController::class, 'onboarding'])->name('chat.onboarding');
    Route::patch('chat/{conversation}/property-preferences', [ChatController::class, 'updatePropertyPreferences'])
        ->middleware('throttle:'.config('chat.throttle.map', '60,1'))
        ->name('chat.property-preferences.update');
    Route::get('chat/{conversation}/messages', [ChatController::class, 'messages'])->name('chat.messages');
    Route::delete('chat/sessions', [ChatController::class, 'destroyAll'])->name('chat.sessions.destroy');
    Route::delete('chat/{conversation}', [ChatController::class, 'destroy'])->name('chat.destroy');
    Route::get('chat/{conversation}', [ChatController::class, 'show'])->name('chat.show');
});
