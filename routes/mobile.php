<?php

use App\Http\Controllers\v1\DashboardController;
use App\Http\Controllers\v1\MedicineController;
use App\Http\Controllers\v1\MobileAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile/v1')->group(function () {
    Route::get('/companies-public', [MobileAuthController::class, 'companies']);
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/medicine', [MedicineController::class, 'index']);

    Route::fallback(function (Request $request) {
        return response()->json([
            'success' => false,
            'message' => 'Mobile endpoint not found',
            'path' => $request->path(),
        ], 404);
    });
});
