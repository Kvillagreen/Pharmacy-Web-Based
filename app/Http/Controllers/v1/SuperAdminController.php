<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\v1\Branch;
use App\Models\v1\Company;
use App\Models\v1\SystemAuditLog;
use App\Models\v1\Transaction;
use App\Models\v1\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SuperAdminController extends Controller
{
    private function audit(Request $request, string $action, ?string $details = null): void
    {
        if (!Schema::hasTable('system_audit_logs')) {
            return;
        }

        SystemAuditLog::create([
            'user_id' => $request->user()?->user_id,
            'action' => $action,
            'ip_address' => $request->ip(),
            'details' => $details,
        ]);
    }

    public function dashboard()
    {
        $companies = Company::count();
        $branches = Branch::where('status', 'active')->count();
        $users = User::whereIn('status', ['approved', 'pending', 'rejected'])->count();
        $pendingAdmins = User::where('role', 'admin')->where('status', 'pending')->count();
        $activeUsers = User::where('status', 'approved')->count();
        $transactionsToday = Transaction::whereDate('created_at', now()->toDateString())->count();

        $recentAdmins = User::with('branch.company')
            ->where('role', 'admin')
            ->latest('created_at')
            ->limit(8)
            ->get()
            ->map(fn ($user) => [
                'user_id' => $user->user_id,
                'full_name' => trim($user->first_name . ' ' . $user->last_name),
                'email' => $user->email,
                'status' => $user->status,
                'company_name' => $user->branch?->company?->company_name,
                'branch_name' => $user->branch?->branch_name,
                'created_at' => $user->created_at,
                'last_login_ip' => Schema::hasColumn('users', 'last_login_ip') ? $user->last_login_ip : null,
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'companies' => $companies,
                    'active_branches' => $branches,
                    'users' => $users,
                    'active_users' => $activeUsers,
                    'pending_admins' => $pendingAdmins,
                    'transactions_today' => $transactionsToday,
                ],
                'recent_admins' => $recentAdmins,
            ],
        ]);
    }

    public function companies()
    {
        $companies = Cache::remember('superadmin_companies', 30, function () {
            return Company::with(['branches' => fn ($query) => $query->orderBy('branch_name')])
                ->orderBy('company_name')
                ->get()
                ->map(function ($company) {
                    $adminCount = User::whereHas('branch', fn ($query) => $query->where('company_id', $company->company_id))
                        ->where('role', 'admin')
                        ->whereIn('status', ['approved', 'pending'])
                        ->count();

                    return [
                    'company_id' => $company->company_id,
                    'company_name' => $company->company_name,
                    'company_email' => $company->company_email,
                    'tin_number' => $company->tin_number,
                    'branches_count' => $company->branches->count(),
                    'admins_count' => $adminCount,
                    'branches' => $company->branches->map(fn ($branch) => [
                        'branch_id' => $branch->branch_id,
                        'branch_name' => $branch->branch_name,
                        'branch_address' => $branch->branch_address,
                        'branch_contact' => $branch->branch_contact,
                        'status' => $branch->status,
                    ])->values(),
                ];
            });
        });

        return response()->json([
            'success' => true,
            'data' => $companies,
        ]);
    }

    public function createCompany(Request $request)
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255', 'unique:companies,company_name'],
            'company_email' => ['required', 'email', 'max:255', 'unique:companies,company_email'],
            'tin_number' => ['required', 'string', 'max:255', 'unique:companies,tin_number'],
        ]);

        $company = Company::create($validated);
        $this->audit($request, 'company_created', 'Created company: ' . $company->company_name);
        Cache::forget('superadmin_companies');

        return response()->json([
            'success' => true,
            'message' => 'Company created successfully.',
            'data' => $company,
        ], 201);
    }

    public function createBranch(Request $request)
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,company_id'],
            'branch_name' => ['required', 'string', 'max:255', 'unique:branches,branch_name'],
            'branch_address' => ['required', 'string', 'max:500'],
            'branch_contact' => ['required', 'string', 'max:50'],
            'status' => ['nullable', 'in:active,inactive,deleted'],
        ]);

        $branch = Branch::create([
            'company_id' => $validated['company_id'],
            'branch_name' => trim($validated['branch_name']),
            'branch_address' => trim($validated['branch_address']),
            'branch_contact' => trim($validated['branch_contact']),
            'status' => $validated['status'] ?? 'active',
        ]);

        $this->audit($request, 'branch_created', 'Created branch: ' . $branch->branch_name);
        Cache::forget('superadmin_companies');
        Cache::forget('public_branch_list');

        return response()->json([
            'success' => true,
            'message' => 'Branch created successfully.',
            'data' => $branch,
        ], 201);
    }

    public function pendingAdmins()
    {
        $admins = User::with('branch.company')
            ->where('role', 'admin')
            ->where('status', 'pending')
            ->latest('created_at')
            ->get()
            ->map(fn ($user) => [
                'user_id' => $user->user_id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'company_name' => $user->branch?->company?->company_name,
                'branch_name' => $user->branch?->branch_name,
                'registered_ip' => Schema::hasColumn('users', 'registered_ip') ? $user->registered_ip : null,
                'created_at' => $user->created_at,
            ]);

        return response()->json([
            'success' => true,
            'data' => $admins,
        ]);
    }

    public function updateAdminApproval(Request $request, string $id, string $status)
    {
        if (!in_array($status, ['approved', 'rejected'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid approval status.',
            ], 422);
        }

        $user = User::where('role', 'admin')->find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Admin user not found.',
            ], 404);
        }

        $user->update(['status' => $status]);
        $this->audit($request, 'admin_' . $status, 'Updated admin #' . $user->user_id . ' to ' . $status);

        return response()->json([
            'success' => true,
            'message' => 'Admin user updated successfully.',
        ]);
    }

    public function logs(Request $request)
    {
        $limit = max(50, min((int) $request->input('limit', 200), 500));
        $logFile = storage_path('logs/laravel.log');
        $logLines = [];

        if (file_exists($logFile)) {
            $contents = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $logLines = array_slice($contents, -1 * $limit);
        }

        $auditLogs = SystemAuditLog::with('user:user_id,first_name,last_name,email')
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(fn ($log) => [
                'action' => $log->action,
                'ip_address' => $log->ip_address,
                'details' => $log->details,
                'created_at' => $log->created_at,
                'user_name' => trim(($log->user?->first_name ?? '') . ' ' . ($log->user?->last_name ?? '')),
                'user_email' => $log->user?->email,
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'application_logs' => array_values($logLines),
                'audit_logs' => $auditLogs,
            ],
        ]);
    }

    public function analytics()
    {
        $analyticsData = Cache::remember('superadmin_analytics', 30, function () {
            $usersByRole = User::select('role', DB::raw('COUNT(*) as total'))
                ->whereIn('status', ['approved', 'pending', 'rejected'])
                ->groupBy('role')
                ->orderBy('role')
                ->get();

            $usersByStatus = User::select('status', DB::raw('COUNT(*) as total'))
                ->groupBy('status')
                ->orderBy('status')
                ->get();

            $companyUserAnalytics = Company::query()
                ->leftJoin('branches', 'companies.company_id', '=', 'branches.company_id')
                ->leftJoin('users', 'branches.branch_id', '=', 'users.branch_id')
                ->selectRaw('companies.company_id, companies.company_name, COUNT(DISTINCT branches.branch_id) as branch_count, COUNT(users.user_id) as user_count')
                ->groupBy('companies.company_id', 'companies.company_name')
                ->orderByDesc('user_count')
                ->get();

            $recentLogins = User::with('branch.company')
                ->whereNotNull('login_at')
                ->latest('login_at')
                ->limit(20)
                ->get()
                ->map(fn ($user) => [
                    'user_id' => $user->user_id,
                    'full_name' => trim($user->first_name . ' ' . $user->last_name),
                    'email' => $user->email,
                    'role' => $user->role,
                    'status' => $user->status,
                    'company_name' => $user->branch?->company?->company_name,
                    'branch_name' => $user->branch?->branch_name,
                    'login_at' => $user->login_at,
                    'last_login_ip' => Schema::hasColumn('users', 'last_login_ip') ? $user->last_login_ip : null,
                    'registered_ip' => Schema::hasColumn('users', 'registered_ip') ? $user->registered_ip : null,
                ]);

            return [
                'users_by_role' => $usersByRole,
                'users_by_status' => $usersByStatus,
                'company_user_analytics' => $companyUserAnalytics,
                'recent_logins' => $recentLogins,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => array_merge([
                'summary' => [
                    'companies' => Company::count(),
                    'branches' => Branch::count(),
                    'users' => User::count(),
                    'approved_admins' => User::where('role', 'admin')->where('status', 'approved')->count(),
                    'pending_admins' => User::where('role', 'admin')->where('status', 'pending')->count(),
                ],
            ], $analyticsData),
        ]);
    }

    public function profile(Request $request)
    {
        $user = $request->user()?->load('branch.company');

        return response()->json([
            'success' => true,
            'data' => [
                'user_id' => $user?->user_id,
                'first_name' => $user?->first_name,
                'last_name' => $user?->last_name,
                'email' => $user?->email,
                'role' => $user?->role,
                'address' => $user?->address,
                'company_name' => $user?->branch?->company?->company_name,
                'branch_name' => $user?->branch?->branch_name,
                'login_at' => $user?->login_at,
                'last_login_ip' => Schema::hasColumn('users', 'last_login_ip') ? $user?->last_login_ip : null,
                'registered_ip' => Schema::hasColumn('users', 'registered_ip') ? $user?->registered_ip : null,
            ],
        ]);
    }

    public function updateProfile(Request $request)
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . auth()->id() . ',user_id'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();
        $user->update($validated);

        $this->audit($request, 'super_admin_profile_updated', 'Updated super admin profile.');

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
        ]);
    }
}
