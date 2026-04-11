<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\v1\RegisterRequest;
use App\Http\Requests\v1\LoginRequest;
use App\Models\v1\Permission;
use App\Models\v1\User;
use App\Models\v1\Branch;
use App\Models\v1\SystemAuditLog;
use App\Models\v1\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private function availableUserColumns(array $columns): array
    {
        return array_values(array_filter($columns, fn ($column) => Schema::hasColumn('users', $column)));
    }

    private function safeUserUpdate(User $user, array $attributes): void
    {
        $allowedColumns = $this->availableUserColumns(array_keys($attributes));

        if (empty($allowedColumns)) {
            return;
        }

        $user->update(array_intersect_key($attributes, array_flip($allowedColumns)));
    }

    private function safeAuditLog(array $attributes): void
    {
        if (!Schema::hasTable('system_audit_logs')) {
            return;
        }

        SystemAuditLog::create($attributes);
    }

    /**
     * Standard API Response
     */
    private function response($success, $message, $data = null, $extra = [])
    {
        return response()->json(array_merge([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], $extra));
    }

    /**
     * Login
     */
    public function login(LoginRequest $request)
    {
        try{
            $validated = $request->validated();
        $key = Str::lower($validated['email']) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return $this->response(false, 'Too many login attempts. Try again later.');
        }

        $user = User::with('permissions')
            ->where('email', $validated['email'])
            ->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($key, 60);
            return $this->response(false, 'Invalid credentials');
        }

        if ($user->status !== 'approved') {
            return $this->response(false, 'Your account is not yet approved');
        }

        RateLimiter::clear($key);

        // Remove old tokens
        $user->tokens()->delete();

        // Get permission names
        $permissionNames = $user->permissions
            ->pluck('permission_name')
            ->values()
            ->toArray();

        // Create token using permission names as Sanctum abilities
        $token = $user->createToken(
            'auth_token',
            $permissionNames,
            Carbon::now()->addHours(8)
        );

        $this->safeUserUpdate($user, [
            'login_at' => now(),
            'last_login_ip' => $request->ip(),
            'last_seen_ip' => $request->ip(),
        ]);

        $this->safeAuditLog([
            'user_id' => $user->user_id,
            'action' => 'login',
            'ip_address' => $request->ip(),
            'details' => 'User login successful.',
        ]);

        $companyData = User::join('branches', 'users.branch_id', '=', 'branches.branch_id')
            ->join('companies', 'branches.company_id', '=', 'companies.company_id')
            ->where('users.user_id', $user->user_id)
            ->select([
                'companies.company_id',
                'companies.company_name',
                'companies.company_email',
                'companies.tin_number',
            ])
            ->first();

        $responseUser = [
            'user_id' => $user->user_id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'branch_id' => $user->branch_id,
            'role' => $user->role,
            'address' => $user->address,
            'status' => $user->status,
            'company_id' => $companyData?->company_id,
            'company_name' => $companyData?->company_name,
            'company_email' => $companyData?->company_email,
            'tin_number' => $companyData?->tin_number,
            'permissions' => $permissionNames,
            'created_at' => $user->created_at,
            'login_at' => $user->login_at,

        ];

        return $this->response(true, 'Login successful', $responseUser, [
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at ?? Carbon::now()->addHours(8),
        ]);
        }
        catch(\Exception $e){
            \Log::error('Login error', [
                'error' => $e->getMessage(),
                'stack' => $e->getTraceAsString(),
            ]);

            return $this->response(false, 'Unable to process login at this time. Please try again later.');
        }
    }

    /**
     * Logout
     */
    public function logout(Request $request)
    {
        $token = $request->user()?->currentAccessToken();

        if ($token) {
            $token->delete();
        }
        return $this->response(true, 'Logged out');
    }

    /**
     * Register
     */
    public function register(RegisterRequest $request)
    {
        $validated = $request->validated();

        try {
            $createPayload = [
                'first_name' => $validated['firstName'],
                'last_name' => $validated['lastName'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'branch_id' => $validated['branchId'],
                'role' => $validated['role'],
                'address' => $validated['address'],
                'status' => 'pending',
            ];

            if (Schema::hasColumn('users', 'registered_ip')) {
                $createPayload['registered_ip'] = $request->ip();
            }

            if (Schema::hasColumn('users', 'last_seen_ip')) {
                $createPayload['last_seen_ip'] = $request->ip();
            }

            $user = User::create($createPayload);

            $user->permissions()->sync(1);
            $user->load('permissions');

            return $this->response(true, 'Account created successfully', [
                'user_id' => $user->user_id,
            ]);
        } catch (\Exception $e) {
            \Log::error('Register error', [
                'error' => $e->getMessage(),
                'payload' => $request->except('password'),
            ]);

            return $this->response(false, 'Failed to create account. Please verify your input and try again.');
        }
    }

    /**
     * Check Auth
     */
    public function AuthUser(Request $request)
    {
        $user = User::with('permissions')->find(auth()->id());

        if (!$user) {
            return $this->response(false, 'User not logged in', null, [
                'authenticated' => false,
            ]);
        }

        if ($user->status !== 'approved') {
            return $this->response(false, 'User not approved', null, [
                'authenticated' => false,
            ]);
        }
        $companyData = User::join('branches', 'users.branch_id', '=', 'branches.branch_id')
                    ->join('companies', 'branches.company_id', '=', 'companies.company_id')
                    ->where('users.user_id', $user->user_id)
                    ->select([
                        'companies.company_id',
                        'companies.company_name',
                        'companies.company_email',
                        'companies.tin_number',
                    ])
                    ->first();

        $permissionNames = $user->permissions
            ->pluck('permission_name')
            ->values()
            ->toArray();

        $this->safeUserUpdate($user, [
            'last_seen_ip' => $request->ip(),
        ]);

        return $this->response(true, 'Authenticated', [
            'user_id' => $user->user_id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'branch_id' => $user->branch_id,
            'role' => $user->role,
            'address' => $user->address,
            'status' => $user->status,
            'company_id' => $companyData?->company_id,
            'company_name' => $companyData?->company_name,
            'company_email' => $companyData?->company_email,
            'tin_number' => $companyData?->tin_number,
            'permissions' => $permissionNames,
            'created_at' => $user->created_at,
            'login_at' => $user->login_at,

        ], [
            'authenticated' => true,
        ]);
    }

    public function headerNotifications(Request $request)
    {
        $authUser = User::with('permissions')->find(auth()->id());

        if (!$authUser) {
            return $this->response(false, 'User not logged in', null, [
                'authenticated' => false,
            ]);
        }

        $selectedBranchId = (int) $request->input('branch_id', $authUser->branch_id);
        $companyId = (int) $request->input('company_id', 0);

        $branchQuery = Branch::query()->where('status', 'active');

        if ($selectedBranchId > 0) {
            $branchQuery->where('branch_id', $selectedBranchId);
        } elseif ($companyId > 0) {
            $branchQuery->where('company_id', $companyId);
        } else {
            $branchQuery->where('branch_id', $authUser->branch_id);
        }

        $scopeBranchIds = $branchQuery->pluck('branch_id');

        $transactionNotifications = Transaction::query()
            ->with(['branch:branch_id,branch_name', 'user:user_id,first_name,last_name'])
            ->whereIn('branch_id', $scopeBranchIds)
            ->latest('created_at')
            ->limit(8)
            ->get()
            ->map(function ($transaction) {
                $cashierName = trim(($transaction->user?->first_name ?? '') . ' ' . ($transaction->user?->last_name ?? ''));

                return [
                    'type' => 'transaction',
                    'title' => 'New transaction recorded',
                    'message' => $cashierName
                        ? $cashierName . ' processed a transaction at ' . ($transaction->branch?->branch_name ?? 'the selected branch') . '.'
                        : 'A new transaction was recorded at ' . ($transaction->branch?->branch_name ?? 'the selected branch') . '.',
                    'amount' => (float) $transaction->total_amount,
                    'branch_name' => $transaction->branch?->branch_name,
                    'created_at' => $transaction->created_at,
                ];
            });

        $userNotifications = collect();

        if ($authUser->hasPermission('users')) {
            $userNotifications = User::query()
                ->with(['branch:branch_id,branch_name'])
                ->whereIn('branch_id', $scopeBranchIds)
                ->where('user_id', '!=', $authUser->user_id)
                ->latest('created_at')
                ->limit(8)
                ->get()
                ->map(function ($user) {
                    $fullName = trim($user->first_name . ' ' . $user->last_name);

                    return [
                        'type' => 'user',
                        'title' => 'New user registration',
                        'message' => $fullName . ' registered under ' . ($user->branch?->branch_name ?? 'the selected branch') . '.',
                        'status' => $user->status,
                        'branch_name' => $user->branch?->branch_name,
                        'created_at' => $user->created_at,
                    ];
                });
        }

        $notifications = $transactionNotifications
            ->concat($userNotifications)
            ->sortByDesc('created_at')
            ->take(12)
            ->values();

        return $this->response(true, 'Header notifications fetched successfully', [
            'notifications' => $notifications,
            'unread_count' => $notifications->count(),
        ]);
    }

    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'different:current_password'],
            'new_password_confirmation' => ['required', 'same:new_password'],
        ]);

        $user = User::find(auth()->id());

        if (!$user) {
            return $this->response(false, 'User not found', null, [
                'authenticated' => false,
            ]);
        }

        if (!Hash::check($validated['current_password'], $user->password)) {
            return $this->response(false, 'Current password is incorrect');
        }

        $user->update([
            'password' => Hash::make($validated['new_password']),
        ]);

        return $this->response(true, 'Password changed successfully');
    }
}
