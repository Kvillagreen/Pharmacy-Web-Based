<?php
use App\Http\Controllers\v1\BranchController;
use App\Http\Controllers\v1\MedicineController;
use App\Http\Controllers\v1\PostController;
use App\Http\Controllers\v1\TransactionController;
use App\Http\Controllers\v1\FefoController;
use App\Http\Controllers\v1\DashboardController;
use App\Http\Controllers\v1\ControlledDrugController;
use App\Http\Controllers\v1\ReportController;
use App\Http\Controllers\v1\SuperAdminController;
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

    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/auth-user', [AuthController::class,'AuthUser']);
        Route::apiResource('/branch', BranchController::class);
        Route::apiResource('/permissions', PermissionController::class);
        Route::get('/header/notifications', [AuthController::class, 'headerNotifications']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/controlled-drugs', [ControlledDrugController::class, 'index']);
        Route::get('/reports', [ReportController::class, 'index']);
        Route::get('/reports/bir-annual', [ReportController::class, 'birAnnualDeclaration']);
        Route::apiResource('/medicine', MedicineController::class);
        Route::apiResource('/fefo', FefoController::class);
        Route::apiResource('/transaction', TransactionController::class);
        Route::apiResource('/company', CompanyController::class);

        // User management routes
        Route::post('/user/update-status/{id}/{status}', [UserController::class, 'updateUserStatus']);
        Route::post('/user/update-branch/{id}/{branch_id}', [UserController::class, 'updateUserBranch']);
        Route::get('/user/permissions/options', [UserController::class, 'permissionOptions']);
        Route::get('/user/{id}/permissions', [UserController::class, 'userPermissions']);
        Route::put('/user/{id}/permissions', [UserController::class, 'updateUserPermissions']);
        Route::apiResource('/user', UserController::class);


    });

    Route::middleware(['auth:sanctum', 'super_admin'])->prefix('admin')->group(function () {
        Route::get('/dashboard', [SuperAdminController::class, 'dashboard']);
        Route::get('/companies', [SuperAdminController::class, 'companies']);
        Route::post('/companies', [SuperAdminController::class, 'createCompany']);
        Route::post('/branches', [SuperAdminController::class, 'createBranch']);
        Route::get('/approvals/admins', [SuperAdminController::class, 'pendingAdmins']);
        Route::post('/approvals/admins/{id}/{status}', [SuperAdminController::class, 'updateAdminApproval']);
        Route::get('/logs', [SuperAdminController::class, 'logs']);
        Route::get('/analytics', [SuperAdminController::class, 'analytics']);
        Route::get('/profile', [SuperAdminController::class, 'profile']);
        Route::put('/profile', [SuperAdminController::class, 'updateProfile']);
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


return response()->json([
            'success' => false,
            'message' => 'Endpoint not found',
        ], 404);
    });

});
