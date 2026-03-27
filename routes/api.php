<?php
use App\Http\Controllers\v1\BranchController;
use App\Http\Controllers\v1\MedicineController;
use App\Http\Controllers\v1\PostController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\v1\UserController;
use App\Http\Controllers\v1\AuthController;


Route::group(['prefix'=> 'v1',  'namespace' => 'App\Http\Controllers\v1'], function() {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/branch-public', [BranchController::class,'branch']);
    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/auth-user', [AuthController::class,'AuthUser']);
        Route::apiResource('/user', UserController::class);
        Route::apiResource('/medicine', MedicineController::class);
        Route::post('/medicine', [MedicineController::class, 'create']);
    });

});
