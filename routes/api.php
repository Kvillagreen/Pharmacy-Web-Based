<?php
use App\Http\Controllers\v1\BranchController;
use App\Http\Controllers\v1\MedicineController;
use App\Http\Controllers\v1\PostController;
use App\Http\Controllers\v1\TransactionController;
use App\Http\Controllers\v1\FefoController;
use App\Http\Controllers\v1\DashboardController;
use App\Http\Controllers\v1\ControlledDrugController;
use App\Http\Controllers\v1\ReportController;
use App\Http\Controllers\v1\SmsController;
use App\Http\Controllers\v1\SuperAdminController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\v1\UserController;
use App\Http\Controllers\v1\PermissionController;
use App\Http\Controllers\v1\PasswordResetController;
use App\Http\Controllers\v1\AuthController;
use App\Http\Controllers\v1\CompanyController;
use App\Http\Controllers\v1\InventoryTransferController;
use App\Http\Controllers\v1\SuperAdminAuthController;

Route::group(['prefix'=> 'v1',  'namespace' => 'App\Http\Controllers\v1'], function() {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/forgot-password/request', [PasswordResetController::class, 'request']);
    Route::post('/forgot-password/resend', [PasswordResetController::class, 'resend']);
    Route::post('/forgot-password/reset', [PasswordResetController::class, 'reset']);
    Route::post('/branch-public', [BranchController::class,'branch']);
    Route::get('/catalog', [MedicineController::class, 'publicCatalog']);
    Route::post('/admin/login', [SuperAdminAuthController::class, 'login']);

    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/header/notifications', [AuthController::class, 'headerNotifications']);
        Route::put('/header/notifications/{id}/read', [AuthController::class, 'markNotificationRead']);
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/controlled-drugs', [ControlledDrugController::class, 'index']);
        Route::get('/reports', [ReportController::class, 'index']);
        Route::get('/reports/bir-annual', [ReportController::class, 'birAnnualDeclaration']);
        Route::get('/sms/replies', [SmsController::class, 'replies']);
        Route::get('/sms/logs', [SmsController::class, 'logs']);
        Route::get('/user/permissions/options', [UserController::class, 'permissionOptions']);
        Route::get('/user/{id}/permissions', [UserController::class, 'userPermissions']);
        Route::get('/branch', [BranchController::class, 'index']);
        Route::get('/branch/{branch}', [BranchController::class, 'show']);
        Route::get('/permissions', [PermissionController::class, 'index']);
        Route::get('/permissions/{permission}', [PermissionController::class, 'show']);
        Route::get('/medicine', [MedicineController::class, 'index']);
        Route::get('/medicine/{medicine}', [MedicineController::class, 'show']);
        Route::get('/fefo', [FefoController::class, 'index']);
        Route::get('/fefo/{fefo}', [FefoController::class, 'show']);
        Route::get('/transaction', [TransactionController::class, 'index']);
        Route::get('/transaction/{transaction}', [TransactionController::class, 'show']);
        Route::get('/inventory-transfer', [InventoryTransferController::class, 'index']);
        Route::get('/company', [CompanyController::class, 'index']);
        Route::get('/company/{company}', [CompanyController::class, 'show']);
        Route::get('/user', [UserController::class, 'index']);
        Route::get('/user/{user}', [UserController::class, 'show']);

    });

    Route::middleware(['auth:sanctum', 'prevent.concurrent'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/auth-user', [AuthController::class,'AuthUser']);
        Route::get('/settings', [AuthController::class, 'settings']);
        Route::put('/settings/notifications', [AuthController::class, 'updateNotificationPreferences']);
        Route::post('/settings/revoke-other-sessions', [AuthController::class, 'revokeOtherSessions']);
        Route::apiResource('/branch', BranchController::class)->except(['index', 'show']);
        Route::apiResource('/permissions', PermissionController::class)->except(['index', 'show']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
        Route::post('/medicine/merge-duplicates', [MedicineController::class, 'mergeDuplicates']);
        Route::post('/sms/messages', [SmsController::class, 'send']);
        Route::delete('/sms/messages/{messageId}', [SmsController::class, 'destroyMessage']);
        Route::delete('/sms/conversations/{counterpartyNumber}', [SmsController::class, 'destroyConversation']);
        Route::apiResource('/medicine', MedicineController::class)->except(['index', 'show']);
        Route::apiResource('/fefo', FefoController::class)->except(['index', 'show']);
        Route::apiResource('/transaction', TransactionController::class)->except(['index', 'show']);
        Route::post('/inventory-transfer', [InventoryTransferController::class, 'store']);
        Route::post('/inventory-transfer/{id}/accept', [InventoryTransferController::class, 'accept']);
        Route::post('/inventory-transfer/{id}/decline', [InventoryTransferController::class, 'decline']);
        Route::apiResource('/company', CompanyController::class)->except(['index', 'show']);
        Route::post('/user/update-status/{id}/{status}', [UserController::class, 'updateUserStatus']);
        Route::post('/user/update-branch/{id}/{branch_id}', [UserController::class, 'updateUserBranch']);
        Route::put('/user/{id}/permissions', [UserController::class, 'updateUserPermissions']);
        Route::apiResource('/user', UserController::class)->except(['index', 'show']);
    });

    Route::middleware(['auth:sanctum', 'super_admin'])->prefix('admin')->group(function () {
        Route::post('/logout', [SuperAdminAuthController::class, 'logout']);
        Route::post('/auth-user', [SuperAdminAuthController::class, 'authUser']);
        Route::post('/change-password', [SuperAdminAuthController::class, 'changePassword']);
        Route::get('/dashboard', [SuperAdminController::class, 'dashboard']);
        Route::get('/companies', [SuperAdminController::class, 'companies']);
        Route::post('/companies', [SuperAdminController::class, 'createCompany']);
        Route::get('/companies/{id}/validate-delete', [SuperAdminController::class, 'validateCompanyDeletion']);
        Route::post('/companies/{id}/delete', [SuperAdminController::class, 'deleteCompany']);
        Route::post('/branches', [SuperAdminController::class, 'createBranch']);
        Route::get('/branches/{id}/validate-delete', [SuperAdminController::class, 'validateBranchDeletion']);
        Route::post('/branches/{id}/delete', [SuperAdminController::class, 'deleteBranch']);
        Route::get('/approvals/admins', [SuperAdminController::class, 'pendingAdmins']);
        Route::post('/approvals/admins/{id}/{status}', [SuperAdminController::class, 'updateAdminApproval']);
        Route::get('/logs', [SuperAdminController::class, 'logs']);
        Route::get('/analytics', [SuperAdminController::class, 'analytics']);
        Route::get('/profile', [SuperAdminController::class, 'profile']);
        Route::put('/profile', [SuperAdminController::class, 'updateProfile']);
        Route::get('/super-admins', [SuperAdminController::class, 'superAdmins']);
        Route::post('/super-admins', [SuperAdminController::class, 'createSuperAdmin']);
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
