<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EventController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\GoogleSyncController;
use App\Http\Controllers\GoogleWebhookController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::get('/google/callback', [GoogleAuthController::class, 'callback']);
Route::post('/google/webhook', [GoogleWebhookController::class, 'handle']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/events', [EventController::class, 'index']);
    Route::post('/events', [EventController::class, 'store']);
    Route::get('/events/{id}', [EventController::class, 'show']);
    Route::put('/events/{id}', [EventController::class, 'update']);
    Route::delete('/events/{id}', [EventController::class, 'destroy']);

    Route::get('/google/redirect', [GoogleAuthController::class, 'redirect']);
    Route::get('/google/status', [GoogleAuthController::class, 'status']);
    Route::post('/google/disconnect', [GoogleAuthController::class, 'disconnect']);

    Route::post('/google/sync', [GoogleSyncController::class, 'sync']);
});
