<?php

use App\Http\Controllers\v1\DashboardController;
use App\Http\Controllers\v1\MedicineController;
use App\Http\Controllers\v1\MobileAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile/v1')->group(function () {
    Route::post('/login', [MobileAuthController::class, 'login']);
    Route::post('/register', [MobileAuthController::class, 'register']);
    Route::get('/companies-public', [MobileAuthController::class, 'companies']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/header/notifications', [MobileAuthController::class, 'headerNotifications']);
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/medicine', [MedicineController::class, 'index']);
        Route::get('/settings', [MobileAuthController::class, 'settings']);
    });

    Route::middleware(['auth:sanctum', 'prevent.concurrent'])->group(function () {
        Route::post('/logout', [MobileAuthController::class, 'logout']);
        Route::put('/settings/notifications', [MobileAuthController::class, 'updateNotificationPreferences']);
        Route::post('/change-password', [MobileAuthController::class, 'changePassword']);
        Route::put('/profile', [MobileAuthController::class, 'updateProfile']);
    });

    Route::fallback(function (Request $request) {
        return response()->json([
            'success' => false,
            'message' => 'Mobile endpoint not found',
            'path' => $request->path(),
        ], 404);
    });
});
