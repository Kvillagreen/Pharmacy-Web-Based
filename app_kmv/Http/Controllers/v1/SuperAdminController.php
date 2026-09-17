<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\v1\Branch;
use App\Models\v1\Batch;
use App\Models\v1\Company;
use App\Models\v1\Inventory;
use App\Models\v1\Medicine;
use App\Models\v1\SystemAuditLog;
use App\Models\v1\SuperAdmin;
use App\Models\v1\TransactionItem;
use App\Models\v1\Transaction;
use App\Models\v1\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SuperAdminController extends Controller
{
    private function clearAdminCaches(): void
    {
        Cache::forget('superadmin_companies');
        Cache::forget('superadmin_analytics');
        Cache::forget('public_branch_list');
    }

    private function audit(Request $request, string $action, ?string $details = null): void
    {
        if (!Schema::hasTable('system_audit_logs')) {
            return;
        }

        SystemAuditLog::create([
            'super_admin_id' => $request->user()?->super_admin_id,
            'action' => $action,
            'ip_address' => $request->ip(),
            'details' => $details,
        ]);
    }

    private function branchDeletionSummary(Branch $branch): array
    {
        $userIds = User::withTrashed()
            ->where('branch_id', $branch->branch_id)
            ->pluck('user_id');

        $branchTransactions = Transaction::query()
            ->where('branch_id', $branch->branch_id);

        $transactionIds = (clone $branchTransactions)->pluck('transaction_id');

        $inventoryRows = Inventory::query()
            ->where('branch_id', $branch->branch_id)
            ->get(['inventory_id', 'medicine_id', 'batch_id']);

        $medicineIds = $inventoryRows->pluck('medicine_id')->filter()->unique()->values();
        $batchIds = $inventoryRows->pluck('batch_id')->filter()->unique()->values();

        $orphanMedicineIds = Medicine::query()
            ->whereIn('medicine_id', $medicineIds)
            ->whereDoesntHave('inventories', function ($query) use ($branch) {
                $query->where('branch_id', '!=', $branch->branch_id);
            })
            ->pluck('medicine_id');

        $orphanBatchIds = Batch::query()
            ->whereIn('batch_id', $batchIds)
            ->whereDoesntHave('inventories', function ($query) use ($branch) {
                $query->where('branch_id', '!=', $branch->branch_id);
            })
            ->pluck('batch_id');

        return [
            'branch_id' => $branch->branch_id,
            'branch_name' => $branch->branch_name,
            'users_count' => $userIds->count(),
            'transactions_count' => $transactionIds->count(),
            'transaction_items_count' => TransactionItem::query()->whereIn('transaction_id', $transactionIds)->count(),
            'inventories_count' => $inventoryRows->count(),
            'medicines_count' => $medicineIds->count(),
            'medicines_to_delete_count' => $orphanMedicineIds->count(),
            'batches_to_delete_count' => $orphanBatchIds->count(),
        ];
    }

    private function deleteBranchTree(Branch $branch): array
    {
        $userIds = User::withTrashed()
            ->where('branch_id', $branch->branch_id)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $transactionIds = Transaction::query()
            ->where('branch_id', $branch->branch_id)
            ->pluck('transaction_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $inventories = Inventory::query()
            ->where('branch_id', $branch->branch_id)
            ->get(['inventory_id', 'medicine_id', 'batch_id']);

        $medicineIds = $inventories->pluck('medicine_id')->filter()->unique()->map(fn ($id) => (int) $id)->all();
        $batchIds = $inventories->pluck('batch_id')->filter()->unique()->map(fn ($id) => (int) $id)->all();

        if (!empty($userIds)) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->whereIn('tokenable_id', $userIds)
                ->delete();

            User::withTrashed()
                ->whereIn('user_id', $userIds)
                ->forceDelete();
        }

        if (!empty($transactionIds)) {
            Transaction::query()
                ->whereIn('transaction_id', $transactionIds)
                ->delete();
        }

        Inventory::query()
            ->where('branch_id', $branch->branch_id)
            ->delete();

        if (!empty($batchIds)) {
            Batch::query()
                ->whereIn('batch_id', $batchIds)
                ->whereDoesntHave('inventories')
                ->delete();
        }

        if (!empty($medicineIds)) {
            Medicine::query()
                ->whereIn('medicine_id', $medicineIds)
                ->whereDoesntHave('inventories')
                ->whereDoesntHave('inventories', function ($query) use ($branch) {
                    $query->where('branch_id', '!=', $branch->branch_id);
                })
                ->whereDoesntHave('transactionItems')
                ->delete();
        }

        $branch->delete();

        return [
            'user_ids' => $userIds,
            'transaction_ids' => $transactionIds,
            'medicine_ids' => $medicineIds,
            'batch_ids' => $batchIds,
        ];
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
        try {
            $companies = Cache::remember('superadmin_companies', 30, function () {
                $adminCounts = Branch::query()
                    ->leftJoin('users', 'branches.branch_id', '=', 'users.branch_id')
                    ->where('users.role', 'admin')
                    ->whereIn('users.status', ['approved', 'pending'])
                    ->groupBy('branches.company_id')
                    ->selectRaw('branches.company_id, COUNT(users.user_id) as admins_count')
                    ->pluck('admins_count', 'branches.company_id');

                return Company::query()
                    ->with(['branches' => fn ($query) => $query->orderBy('branch_name')])
                    ->orderBy('company_name')
                    ->get()
                    ->map(function ($company) use ($adminCounts) {
                        return [
                            'company_id' => (int) $company->company_id,
                            'company_name' => $company->company_name,
                            'company_email' => $company->company_email,
                            'tin_number' => $company->tin_number,
                            'branches_count' => (int) $company->branches->count(),
                            'admins_count' => (int) ($adminCounts[$company->company_id] ?? 0),
                            'branches' => $company->branches->map(fn ($branch) => [
                                'branch_id' => (int) $branch->branch_id,
                                'branch_name' => $branch->branch_name,
                                'branch_address' => $branch->branch_address,
                                'branch_contact' => $branch->branch_contact,
                                'status' => $branch->status,
                            ])->values()->all(),
                        ];
                    })
                    ->values()
                    ->all();
            });

            return response()->json([
                'success' => true,
                'data' => $companies,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to load super admin companies', [
                'error' => $e->getMessage(),
            ]);

            Cache::forget('superadmin_companies');

            return response()->json([
                'success' => false,
                'message' => 'Failed to load companies.',
            ], 500);
        }
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
        $this->clearAdminCaches();

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
        $this->clearAdminCaches();

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
        $logLines = [];
        $loggerChannel = config('logging.default');
        $logLevel = config('logging.channels.' . $loggerChannel . '.level', config('logging.channels.daily.level'));
        $resolvedChannels = $loggerChannel === 'stack'
            ? array_values(array_filter(config('logging.channels.stack.channels', [])))
            : [$loggerChannel];
        $sources = [];
        $readableFiles = [];

        foreach ($resolvedChannels as $channel) {
            $channelConfig = config('logging.channels.' . $channel, []);
            $path = $channelConfig['path'] ?? null;
            $stream = $channelConfig['handler_with']['stream'] ?? null;
            $sourcePath = $path ?: $stream;
            $resolvedPath = $sourcePath;
            $isReadableFile = is_string($path) && is_file($path) && is_readable($path);

            if ($isReadableFile) {
                $fileSource = [
                    'name' => $channel,
                    'path' => $path,
                ];
                $readableFiles[] = $fileSource;
                $resolvedPath = $fileSource['path'];
            } elseif (
                ($channelConfig['driver'] ?? null) === 'daily' &&
                is_string($path)
            ) {
                $dailyFiles = collect(glob(Str::replaceLast('.log', '-*.log', $path)) ?: [])
                    ->filter(fn ($file) => is_file($file) && is_readable($file))
                    ->sort()
                    ->values();

                if ($dailyFiles->isNotEmpty()) {
                    $fileSource = [
                        'name' => $channel,
                        'path' => $dailyFiles->last(),
                    ];
                    $readableFiles[] = $fileSource;
                    $resolvedPath = $fileSource['path'];
                }
            }

            $sources[] = [
                'name' => $channel,
                'path' => $resolvedPath,
                'exists' => is_string($resolvedPath) ? file_exists($resolvedPath) : false,
                'readable' => is_string($resolvedPath) ? is_file($resolvedPath) && is_readable($resolvedPath) : false,
            ];
        }

        if (!empty($readableFiles)) {
            $contents = file($readableFiles[count($readableFiles) - 1]['path'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $logLines = array_slice($contents, -1 * $limit);
        }

        $applicationLogNotice = empty($readableFiles)
            ? 'The active logging channel is not writing to a readable local file. This is expected for deployment setups that stream to stderr, syslog, or external log collectors.'
            : null;

        $auditLogs = Schema::hasTable('system_audit_logs')
            ? SystemAuditLog::with([
                    'user:user_id,first_name,last_name,email',
                    'superAdmin:super_admin_id,first_name,last_name,email',
                ])
                ->latest('created_at')
                ->limit(100)
                ->get()
                ->map(fn ($log) => [
                    'action' => $log->action,
                    'ip_address' => $log->ip_address,
                    'details' => $log->details,
                    'created_at' => $log->created_at,
                    'user_name' => trim(
                        ($log->superAdmin?->first_name ?? $log->user?->first_name ?? '')
                        . ' '
                        . ($log->superAdmin?->last_name ?? $log->user?->last_name ?? '')
                    ),
                    'user_email' => $log->superAdmin?->email ?? $log->user?->email,
                ])
            : collect();

        return response()->json([
            'success' => true,
            'data' => [
                'logger' => [
                    'channel' => $loggerChannel,
                    'stack' => $resolvedChannels,
                    'level' => $logLevel,
                    'sources' => $sources,
                ],
                'application_logs' => array_values($logLines),
                'application_log_notice' => $applicationLogNotice,
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
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'super_admin_id' => $user?->super_admin_id,
                'first_name' => $user?->first_name,
                'last_name' => $user?->last_name,
                'email' => $user?->email,
                'role' => 'super_admin',
                'address' => $user?->address,
                'company_name' => 'System-wide account',
                'branch_name' => null,
                'login_at' => $user?->login_at,
                'last_login_ip' => $user?->last_login_ip,
                'registered_ip' => $user?->registered_ip,
            ],
        ]);
    }

    public function updateProfile(Request $request)
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:super_admins,email,' . $request->user()?->super_admin_id . ',super_admin_id'],
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

    public function superAdmins()
    {
        $superAdmins = SuperAdmin::query()
            ->with('creator:super_admin_id,first_name,last_name,email')
            ->latest('created_at')
            ->get()
            ->map(fn ($admin) => [
                'super_admin_id' => $admin->super_admin_id,
                'first_name' => $admin->first_name,
                'last_name' => $admin->last_name,
                'email' => $admin->email,
                'address' => $admin->address,
                'created_at' => $admin->created_at,
                'created_by' => $admin->creator
                    ? trim($admin->creator->first_name . ' ' . $admin->creator->last_name)
                    : 'System',
                'last_login_ip' => $admin->last_login_ip,
            ]);

        return response()->json([
            'success' => true,
            'data' => $superAdmins,
        ]);
    }

    public function createSuperAdmin(Request $request)
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:super_admins,email'],
            'password' => ['required', 'string', 'min:8'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $superAdmin = SuperAdmin::create([
            'first_name' => trim($validated['first_name']),
            'last_name' => trim($validated['last_name']),
            'email' => strtolower(trim($validated['email'])),
            'password' => Hash::make($validated['password']),
            'address' => isset($validated['address']) ? trim($validated['address']) : null,
            'registered_ip' => $request->ip(),
            'last_seen_ip' => $request->ip(),
            'created_by_super_admin_id' => $request->user()?->super_admin_id,
        ]);

        $this->audit($request, 'super_admin_created', 'Created super admin: ' . $superAdmin->email);

        return response()->json([
            'success' => true,
            'message' => 'Super admin created successfully.',
            'data' => [
                'super_admin_id' => $superAdmin->super_admin_id,
                'email' => $superAdmin->email,
            ],
        ], 201);
    }

    public function validateCompanyDeletion(string $id)
    {
        $company = Company::query()
            ->with(['branches' => fn ($query) => $query->orderBy('branch_name')])
            ->find($id);

        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => 'Company not found.',
            ], 404);
        }

        $branchSummaries = $company->branches
            ->map(fn (Branch $branch) => $this->branchDeletionSummary($branch))
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Company deletion validation loaded.',
            'data' => [
                'company_id' => $company->company_id,
                'company_name' => $company->company_name,
                'company_email' => $company->company_email,
                'tin_number' => $company->tin_number,
                'branches_count' => $branchSummaries->count(),
                'users_count' => $branchSummaries->sum('users_count'),
                'transactions_count' => $branchSummaries->sum('transactions_count'),
                'transaction_items_count' => $branchSummaries->sum('transaction_items_count'),
                'inventories_count' => $branchSummaries->sum('inventories_count'),
                'medicines_count' => $branchSummaries->sum('medicines_count'),
                'medicines_to_delete_count' => $branchSummaries->sum('medicines_to_delete_count'),
                'batches_to_delete_count' => $branchSummaries->sum('batches_to_delete_count'),
                'branches' => $branchSummaries,
            ],
        ]);
    }

    public function deleteCompany(Request $request, string $id)
    {
        $company = Company::query()
            ->with(['branches' => fn ($query) => $query->orderBy('branch_name')])
            ->find($id);

        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => 'Company not found.',
            ], 404);
        }

        $validated = $request->validate([
            'confirmation_name' => ['required', 'string'],
        ]);

        if (trim($validated['confirmation_name']) !== $company->company_name) {
            return response()->json([
                'success' => false,
                'message' => 'Company name confirmation does not match.',
            ], 422);
        }

        DB::beginTransaction();

        try {
            foreach ($company->branches as $branch) {
                $this->deleteBranchTree($branch);
            }

            $company->delete();

            $this->clearAdminCaches();
            $this->audit($request, 'company_deleted', 'Deleted company: ' . $company->company_name);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Company and all related branch data deleted successfully.',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Company deletion failed', [
                'company_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete company.',
            ], 500);
        }
    }

    public function validateBranchDeletion(string $id)
    {
        $branch = Branch::query()->find($id);

        if (!$branch) {
            return response()->json([
                'success' => false,
                'message' => 'Branch not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Branch deletion validation loaded.',
            'data' => $this->branchDeletionSummary($branch),
        ]);
    }

    public function deleteBranch(Request $request, string $id)
    {
        $branch = Branch::query()->find($id);

        if (!$branch) {
            return response()->json([
                'success' => false,
                'message' => 'Branch not found.',
            ], 404);
        }

        $validated = $request->validate([
            'confirmation_name' => ['required', 'string'],
        ]);

        if (trim($validated['confirmation_name']) !== $branch->branch_name) {
            return response()->json([
                'success' => false,
                'message' => 'Branch name confirmation does not match.',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $this->deleteBranchTree($branch);

            $this->clearAdminCaches();
            $this->audit($request, 'branch_deleted', 'Deleted branch: ' . $branch->branch_name);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Branch and all related data deleted successfully.',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Branch deletion failed', [
                'branch_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete branch.',
            ], 500);
        }
    }
}
