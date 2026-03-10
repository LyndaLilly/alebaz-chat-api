<?php

use App\Http\Controllers\BuyerAuthController;
use App\Http\Controllers\CallController;
use App\Http\Controllers\ClientAuthController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\SellerAuthController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Broadcast::routes([
    'middleware' => ['auth:client'],
]);

Route::get('/ping-chat', fn() => 'chat api ok');

Route::prefix('clients')->group(function () {
    Route::post('/start-email', [ClientAuthController::class, 'startEmail']);
    Route::post('/verify-email', [ClientAuthController::class, 'verifyEmail']);
    Route::post('/resend-email', [ClientAuthController::class, 'resendEmail']);
    Route::post('/save-profile', [ClientAuthController::class, 'saveProfile']);
    Route::post('/save-phone-pin', [ClientAuthController::class, 'savePhonePin']);
    Route::post('/login', [ClientAuthController::class, 'loginWithPin']);

});

Route::post('/buyer/login', [BuyerAuthController::class, 'login']);

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
    Route::post('/conversations/{conversation}/voice', [MessageController::class, 'storeVoice']);
    Route::post('/conversations/{conversation}/file', [MessageController::class, 'storeFile']);
    Route::get('/conversations', [ConversationController::class, 'index']);

    Route::patch('/conversations/{conversation}/messages/{message}', [MessageController::class, 'editMessage']);

    Route::delete('/conversations/{conversation}/messages/{message}', [MessageController::class, 'deleteForEveryone']);

    Route::post('/conversations/{conversation}/call-recording', [MessageController::class, 'storeCallRecording']);

    Route::get('/call-recordings', [MessageController::class, 'listCallRecordings']);

    Route::get('/calls/active', [CallController::class, 'active']);
    Route::put('/client/settings', [ClientAuthController::class, 'updateSettings']);
    Route::put('/client/settings/phone', [ClientAuthController::class, 'updatePhone']);
    Route::post('/client/settings/email/request', [ClientAuthController::class, 'requestEmailChange']);
    Route::post('/client/settings/email/confirm', [ClientAuthController::class, 'confirmEmailChange']);
    Route::put('/client/settings/pin', [ClientAuthController::class, 'changePin']);

});

Route::middleware('auth:client')->group(function () {
    Route::get('/conversations', [ConversationController::class, 'index']);

    Route::post('/conversations/{conversation}/clear', [ConversationController::class, 'clearForMe']);
    Route::post('/conversations/{conversation}/hide', [ConversationController::class, 'hideForMe']);
    Route::post('/conversations/{conversation}/unhide', [ConversationController::class, 'unhideForMe']);

    Route::post('/conversations/{conversation}/read', [ConversationController::class, 'markAsRead']);
});

Route::middleware('auth:client')->post('/start-seller-dm', [ConversationController::class, 'createOrGetSellerDm']);

Route::post('/seller/login', [SellerAuthController::class, 'login']);

Route::middleware('auth:seller')->group(function () {
    Route::get('/seller/me', [SellerAuthController::class, 'me']);
});

Route::middleware('auth:client')->get('/sellers', [SellerAuthController::class, 'index']);

Route::middleware('auth:client')->group(function () {
    Route::post('/calls/start', [CallController::class, 'start']);
    Route::post('/calls/{callId}/signal', [CallController::class, 'signal']);
    Route::post('/calls/{callId}/end', [CallController::class, 'end']);
});
