<?php

use App\Http\Controllers\Api\RegisterController;
use App\Http\Controllers\Api\LoginController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\FontController;
use App\Http\Controllers\Api\DesktopSessionController;
use App\Http\Controllers\Api\ThemeController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\MessageController;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\UpdateController;
use Illuminate\Support\Facades\Broadcast;

Route::get('/app-version', function () {
    return response()->json([
        'version' => '1.0.0',
        'version_code' => 5,
        'download_url' => 'https://coremonitor.in/downloads/swiftchat-1.0.0+5.apk',
        'message' => 'Please update to access new features.',
    ]);
});


Route::post('/register', [RegisterController::class, 'register']);
// FIX ME: this route points at RegisterController::verifyOtp, which does
// not exist on that controller — the method is called verifyAccount().
// Either rename the method or point this route at 'verifyAccount'.
Route::post('/verify-otp', [RegisterController::class, 'verifyAccount']);
Route::post('/request-verification-code', [RegisterController::class, 'requestVerificationCode']);

Route::post('/login', [LoginController::class, 'login']);
Route::post('/verify-login-otp', [LoginController::class, 'verifyLoginOtp']);

// Public — desktop page hits these before it has any auth token
Route::get('/desktop-session/{token}/check', [DesktopSessionController::class, 'check']);
Route::post('/desktop-session/{token}/verify', [DesktopSessionController::class, 'verify']);

Route::get('/ping', fn () => response()->json(['status' => 'ok']));

// Authenticated but NOT verification-gated — a freshly registered,
// still-unverified user needs to be able to hit /me (to learn their own
// is_verified status) and /logout. If these sat behind `verified.user`,
// an unverified user could never even ask "am I verified?" without
// already being verified.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', function (Request $request) {
        return $request->user();
    });
    Route::post('/logout', [LoginController::class, 'logout']);

});

Route::middleware(['auth:sanctum', 'verified.user'])->group(function () {
    
    Route::post('/device-token', function (Request $request) {
        $request->validate(['token' => 'required|string']);
        $request->user()->update(['fcm_token' => $request->token]);
        return response()->json(['status' => 'ok']);
    });

    Route::get('/profile', [\App\Http\Controllers\Api\ProfileController::class, 'show']);
    Route::post('/profile', [\App\Http\Controllers\Api\ProfileController::class, 'update']);
    Route::get('/users/{user}', [\App\Http\Controllers\Api\ProfileController::class, 'show']);
    Route::post('/profile/avatar', [\App\Http\Controllers\Api\ProfileController::class, 'updateAvatar']);


    Route::middleware('auth:sanctum')->post('/broadcasting/auth', function (Illuminate\Http\Request $request) {
        return Broadcast::auth($request);
    });

    Route::post('/contacts/sync', [ContactController::class, 'sync']);
    Route::get('/contacts', [ContactController::class, 'index']);
    Route::post('/contacts/{id}/favorite', [ContactController::class, 'toggleFavorite']);
    Route::post('/contacts/{id}/block', [ContactController::class, 'toggleBlock']);

    Route::get('/fonts', [FontController::class, 'index']);
    Route::post('/fonts/select', [FontController::class, 'select']);

    Route::post('/desktop-session/generate', [DesktopSessionController::class, 'generate']);
    Route::get('/desktop-session', [DesktopSessionController::class, 'index']);
    Route::post('/desktop-session/{id}/revoke', [DesktopSessionController::class, 'revoke']);

    Route::get('/themes', [ThemeController::class, 'index']);
    Route::post('/themes', [ThemeController::class, 'store']);
    Route::post('/themes/select', [ThemeController::class, 'select']);

    Route::get('/chats', [ChatController::class, 'index']);
    Route::post('/chats/private', [ChatController::class, 'startPrivate']);
    Route::post('/chats/group', [ChatController::class, 'startGroup']);

    Route::get('/chats/{chatId}/messages', [MessageController::class, 'index']);
    Route::post('/chats/{chatId}/messages', [MessageController::class, 'store']);
    Route::post('/chats/{chatId}/read', [MessageController::class, 'markRead']);
    Route::post('/chats/{chatId}/upload-attachment', [MessageController::class, 'uploadAttachment']);


    // =========================
    // UPDATES
    // =========================

    // Create / view / delete
    Route::get('/updates', [UpdateController::class, 'index']);
    Route::post('/updates', [UpdateController::class, 'store']);
    Route::get('/updates/{uuid}', [UpdateController::class, 'show']);
    Route::post('/updates/{uuid}/view', [UpdateController::class, 'view']);
    Route::delete('/updates/{uuid}', [UpdateController::class, 'destroy']);

    // Reposts
    Route::post('/updates/{uuid}/repost', [UpdateController::class, 'repost']);
    Route::delete('/updates/{uuid}/repost', [UpdateController::class, 'removeRepost']);

    // Update settings
    Route::get('/updates/settings', [UpdateController::class, 'settings']);
    Route::put('/updates/settings', [UpdateController::class, 'updateSettings']);

    // Update visibility blocklist
    Route::get('/updates/blocked-contacts', [UpdateController::class, 'blockedContacts']);
    Route::post('/updates/blocked-contacts/{userId}', [UpdateController::class, 'blockContact']);
    Route::delete('/updates/blocked-contacts/{userId}', [UpdateController::class, 'unblockContact']);


});