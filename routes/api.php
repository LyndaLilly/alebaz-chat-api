<?php

use App\Http\Controllers\ClientAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('clients')->group(function () {
    Route::post('/register', [ClientAuthController::class, 'register']);
    Route::post('/login', [ClientAuthController::class, 'login']);

    Route::middleware('auth:client')->group(function () {
        Route::get('/me', [ClientAuthController::class, 'me']);
        Route::post('/logout', [ClientAuthController::class, 'logout']);
    });
});

Route::prefix('clients')->group(function () {
    Route::post('/start-email', [ClientAuthController::class, 'startEmail']);
    Route::post('/verify-email', [ClientAuthController::class, 'verifyEmail']);
    Route::post('/resend-email', [ClientAuthController::class, 'resendEmail']);

    Route::post('/save-profile', [ClientAuthController::class, 'saveProfile']);
    Route::post('/save-phone-pin', [ClientAuthController::class, 'savePhonePin']);
    Route::post('/login', [ClientAuthController::class, 'loginWithPin']);

});
