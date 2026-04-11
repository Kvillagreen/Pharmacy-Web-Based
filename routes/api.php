<?php
use App\Http\Controllers\v1\BranchController;
use App\Http\Controllers\v1\MedicineController;
use App\Http\Controllers\v1\PostController;
use App\Http\Controllers\v1\TransactionController;
use App\Http\Controllers\v1\FefoController;
use App\Models\v1\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\v1\UserController;
use App\Http\Controllers\v1\PermissionController;
use App\Http\Controllers\v1\AuthController;
use App\Http\Controllers\v1\CompanyController;

Route::group(['prefix'=> 'v1',  'namespace' => 'App\Http\Controllers\v1'], function() {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/branch-public', [BranchController::class,'branch']);
    Route::apiResource('/branch',BranchController::class);
        Route::apiResource('/permissions', PermissionController::class);
    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/auth-user', [AuthController::class,'AuthUser']);
        Route::apiResource('/medicine', MedicineController::class);
        Route::apiResource('/fefo', FefoController::class);
        Route::apiResource('/transaction', TransactionController::class);
        Route::apiResource('/company', CompanyController::class);
        Route::apiResource('/user', UserController::class);

    });

        Route::fallback(function (Request $request) {

            if (!$request->isMethod('post')) { // only allow POST for this endpoint
                return response()->json([
                    'success' => false,
                    'message' => 'Endpoint not found'
                ], 404);
            }
            if (!$request->isMethod('get')) { // only allow POST for this endpoint
                return response()->json([
                    'success' => false,
                    'message' => 'Endpoint not found'
                ], 404);
            }

            if (!$request->isMethod('put')) { // only allow put for this endpoint
                return response()->json([
                    'success' => false,
                    'message' => 'Endpoint not found'
                ], 404);
            }


            if (!$request->isMethod('delete')) { // only allow delete for this endpoint
                return response()->json([
                    'success' => false,
                    'message' => 'Endpoint not found'
                ], 404);
            }
            return response()->json([
                'success' => false,
                'message' => 'Invalid request method or endpoint'
            ], 405);
        });


});
