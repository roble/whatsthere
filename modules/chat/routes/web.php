<?php

use Illuminate\Support\Facades\Route;
use Modules\Chat\Http\Controllers\ChatController;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('chat', [ChatController::class, 'index'])->name('chat.index');
    Route::post('chat/messages', [ChatController::class, 'stream'])->name('chat.stream');
    // Declared before chat/{conversation} so "place" is not read as an id.
    Route::post('chat/place', [ChatController::class, 'place'])->name('chat.place');
    // Filtering before a conversation exists: the landing page already shows
    // results, so its filters have to search something.
    Route::post('chat/property-search', [ChatController::class, 'propertySearch'])->name('chat.property-search');
    Route::post('chat/nearby', [ChatController::class, 'nearby'])->name('chat.nearby');
    Route::patch('chat/{conversation}/onboarding', [ChatController::class, 'onboarding'])->name('chat.onboarding');
    Route::patch('chat/{conversation}/property-preferences', [ChatController::class, 'updatePropertyPreferences'])->name('chat.property-preferences.update');
    Route::get('chat/{conversation}/messages', [ChatController::class, 'messages'])->name('chat.messages');
    Route::get('chat/{conversation}', [ChatController::class, 'show'])->name('chat.show');
});
