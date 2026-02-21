<?php

use App\Http\Controllers\ClientAuthController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\MessageController;
use Illuminate\Support\Facades\Route;


Route::prefix('clients')->group(function () {
    Route::post('/start-email', [ClientAuthController::class, 'startEmail']);
    Route::post('/verify-email', [ClientAuthController::class, 'verifyEmail']);
    Route::post('/resend-email', [ClientAuthController::class, 'resendEmail']);
    Route::post('/save-profile', [ClientAuthController::class, 'saveProfile']);
    Route::post('/save-phone-pin', [ClientAuthController::class, 'savePhonePin']);
    Route::post('/login', [ClientAuthController::class, 'loginWithPin']);

});

Route::middleware('auth:client')->group(function () {
    Route::get('/me', [ClientAuthController::class, 'me']);
    Route::post('/logout', [ClientAuthController::class, 'logout']);
});

Route::middleware('auth:client')->group(function () {

    Route::get('/client/search', [ClientAuthController::class, 'search']);
    Route::get('/client/me', [ClientAuthController::class, 'me']);
    Route::post('/conversations/dm', [ConversationController::class, 'createOrGetDm']);
    Route::get('/conversations/{conversation}/messages', [MessageController::class, 'index']);
    Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store']);
    Route::get('/conversations', [ConversationController::class, 'index']);
});


Route::middleware('auth:client')->group(function () {
  Route::get('/conversations', [ConversationController::class, 'index']);

  Route::post('/conversations/{conversation}/clear', [ConversationController::class, 'clearForMe']);
  Route::post('/conversations/{conversation}/hide',  [ConversationController::class, 'hideForMe']);
  Route::post('/conversations/{conversation}/unhide',  [ConversationController::class, 'unhideForMe']);
});