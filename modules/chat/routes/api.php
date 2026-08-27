<?php

use Modules\Chat\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->group(function () {
    Route::middleware(['auth:sanctum'])->prefix('api/v1')->group(function () {
        Route::apiResource('chat', ChatController::class, ['as' => 'api']);
    });
});
