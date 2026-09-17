<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\v1\RegisterRequest;
use App\Http\Requests\v1\LoginRequest;
use App\Models\v1\Permission;
use App\Models\v1\User;
use App\Models\v1\Branch;
use App\Models\v1\Batch;
use App\Models\v1\Inventory;
use App\Models\v1\SystemAuditLog;
use App\Models\v1\Transaction;
use App\Models\v1\UserNotification;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private const SETTINGS_PERMISSION = 'settings';

    private function rolePermissionNames(string $role): array
    {
        return match ($role) {
            'staff' => ['dashboard', 'sales', 'sms', 'inventory', 'delivery'],
            'pharmacist' => ['sales', 'sms', 'inventory', 'fefo', 'drugs', 'delivery'],
            'branch_manager' => ['dashboard', 'inventory', 'fefo', 'delivery', 'reports'],
            'owner', 'admin', 'super_admin' => ['dashboard', 'sales', 'sms', 'inventory', 'fefo', 'drugs', 'delivery', 'reports', 'users', 'settings', 'branches'],
            default => ['dashboard'],
        };
    }

    private function rolePermissionIds(string $role): array
    {
        return Permission::query()
            ->whereIn('permission_name', $this->rolePermissionNames($role))
            ->pluck('permission_id')
            ->toArray();
    }

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

    private function notificationPreferences(User $user): array
    {
        return [
            'notify_transactions' => (bool) ($user->notify_transactions ?? true),
            'notify_user_registrations' => (bool) ($user->notify_user_registrations ?? true),
            'notify_low_stock' => (bool) ($user->notify_low_stock ?? true),
            'notify_expiry_alerts' => (bool) ($user->notify_expiry_alerts ?? true),
            'notify_security_alerts' => (bool) ($user->notify_security_alerts ?? true),
            'notify_browser' => (bool) ($user->notify_browser ?? true),
        ];
    }

    private function canManageFullSettings(User $user): bool
    {
        return $user->hasPermission(self::SETTINGS_PERMISSION);
    }

    private function companyDataForUser(User $user): ?object
    {
        return User::join('branches', 'users.branch_id', '=', 'branches.branch_id')
            ->join('companies', 'branches.company_id', '=', 'companies.company_id')
            ->where('users.user_id', $user->user_id)
            ->select([
                'companies.company_id',
                'companies.company_name',
                'companies.company_email',
                'companies.tin_number',
                'branches.branch_name',
            ])
            ->first();
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

          $user = User::with(['permissions', 'branch'])
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

        $companyData = $this->companyDataForUser($user);

          $responseUser = [
              'user_id' => $user->user_id,
              'first_name' => $user->first_name,
              'last_name' => $user->last_name,
              'email' => $user->email,
              'branch_id' => $user->branch_id,
              'branch_name' => $user->branch?->branch_name,
              'theme_key' => $user->branch?->theme_key ?? 'emerald',
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

            $permissionIds = $this->rolePermissionIds($validated['role']);

            $user->permissions()->sync($permissionIds);
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
        $companyData = $this->companyDataForUser($user);

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
        $notificationPreferences = $this->notificationPreferences($authUser);

        $storedNotifications = UserNotification::query()
            ->where('user_id', $authUser->user_id)
            ->where(function ($query) use ($scopeBranchIds) {
                $query->whereNull('branch_id')
                    ->orWhereIn('branch_id', $scopeBranchIds);
            })
            ->latest('created_at')
            ->limit(12)
            ->get()
            ->map(fn (UserNotification $notification) => [
                'notification_id' => $notification->user_notification_id,
                'type' => $notification->type,
                'title' => $notification->title,
                'message' => $notification->message,
                'branch_name' => $notification->branch?->branch_name,
                'created_at' => $notification->created_at,
                'read_at' => $notification->read_at,
                'meta' => $notification->meta ?? [],
                'is_actionable' => in_array($notification->type, ['transfer_request'], true)
                    || !empty(($notification->meta ?? [])['inventory_transfer_id']),
            ]);

        $userNotifications = collect();

        if ($notificationPreferences['notify_user_registrations'] && $authUser->hasPermission('users')) {
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

        $lowStockNotifications = collect();
        if ($notificationPreferences['notify_low_stock']) {
            $lowStockNotifications = Inventory::query()
                ->join('medicines', 'inventories.medicine_id', '=', 'medicines.medicine_id')
                ->join('branches', 'inventories.branch_id', '=', 'branches.branch_id')
                ->whereIn('inventories.branch_id', $scopeBranchIds)
                ->whereColumn('inventories.stocks', '<=', 'medicines.reorder_level')
                ->select([
                    'medicines.medicine_name',
                    'inventories.stocks',
                    'medicines.reorder_level',
                    'branches.branch_name',
                    'inventories.updated_at',
                ])
                ->latest('inventories.updated_at')
                ->limit(6)
                ->get()
                ->map(fn ($item) => [
                    'type' => 'inventory',
                    'title' => 'Low stock alert',
                    'message' => ($item->medicine_name ?? 'A medicine') . ' is at ' . (int) $item->stocks . ' stock level in ' . ($item->branch_name ?? 'the selected branch') . '.',
                    'branch_name' => $item->branch_name,
                    'created_at' => $item->updated_at,
                ]);
        }

        $expiryNotifications = collect();
        if ($notificationPreferences['notify_expiry_alerts']) {
            $expiryNotifications = Batch::query()
                ->join('inventories', 'batches.batch_id', '=', 'inventories.batch_id')
                ->join('medicines', 'inventories.medicine_id', '=', 'medicines.medicine_id')
                ->join('branches', 'inventories.branch_id', '=', 'branches.branch_id')
                ->whereIn('inventories.branch_id', $scopeBranchIds)
                ->whereDate('batches.expiry_date', '<=', now()->addDays(30)->toDateString())
                ->select([
                    'medicines.medicine_name',
                    'branches.branch_name',
                    'batches.expiry_date',
                ])
                ->orderBy('batches.expiry_date')
                ->limit(6)
                ->get()
                ->map(fn ($item) => [
                    'type' => 'expiry',
                    'title' => 'Expiry alert',
                    'message' => ($item->medicine_name ?? 'A medicine') . ' is due to expire on ' . Carbon::parse($item->expiry_date)->format('F j, Y') . ' in ' . ($item->branch_name ?? 'the selected branch') . '.',
                    'branch_name' => $item->branch_name,
                    'created_at' => $item->expiry_date,
                ]);
        }

        $securityNotifications = collect();
        if ($notificationPreferences['notify_security_alerts'] && $authUser->last_login_ip) {
            $securityNotifications = collect([[
                'type' => 'security',
                'title' => 'Recent security activity',
                'message' => 'Your last successful login was recorded from IP ' . $authUser->last_login_ip . '.',
                'branch_name' => null,
                'created_at' => $authUser->login_at ?? now(),
            ]]);
        }

        $notifications = $storedNotifications
            ->concat($userNotifications)
            ->concat($lowStockNotifications)
            ->concat($expiryNotifications)
            ->concat($securityNotifications)
            ->sortByDesc(function ($notification) {
                return !empty($notification['is_actionable']) ? 1 : 0;
            })
            ->sortByDesc('created_at')
            ->take(12)
            ->values();

        return $this->response(true, 'Header notifications fetched successfully', [
            'notifications' => $notifications,
            'unread_count' => $notifications->count(),
        ]);
    }

    public function markNotificationRead(Request $request, string $id)
    {
        $user = User::find(auth()->id());

        if (!$user) {
            return $this->response(false, 'User not found');
        }

        $notification = UserNotification::query()
            ->where('user_notification_id', $id)
            ->where('user_id', $user->user_id)
            ->first();

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        }

        $notification->update(['read_at' => now()]);

        return $this->response(true, 'Notification marked as read');
    }

    public function settings(Request $request)
    {
        $user = User::with(['permissions', 'branch.company'])->find(auth()->id());

        if (!$user) {
            return $this->response(false, 'User not logged in', null, [
                'authenticated' => false,
            ]);
        }

        $permissionNames = $user->permissions
            ->pluck('permission_name')
            ->values()
            ->toArray();

        $token = $request->user()?->currentAccessToken();
        $companyData = $this->companyDataForUser($user);

        return $this->response(true, 'Settings loaded successfully', [
              'profile' => [
                  'user_id' => $user->user_id,
                  'first_name' => $user->first_name,
                  'last_name' => $user->last_name,
                  'email' => $user->email,
                  'address' => $user->address,
                  'branch_id' => $user->branch_id,
                  'branch_name' => $user->branch?->branch_name ?? $companyData?->branch_name,
                  'theme_key' => $user->branch?->theme_key ?? 'emerald',
                  'company_id' => $companyData?->company_id,
                  'company_name' => $companyData?->company_name,
                  'company_email' => $companyData?->company_email,
                'tin_number' => $companyData?->tin_number,
            ],
            'access' => [
                'role' => $user->role,
                'status' => $user->status,
                'permission_count' => count($permissionNames),
                'permissions' => $permissionNames,
                'can_manage_all_settings' => $this->canManageFullSettings($user),
            ],
            'notifications' => $this->notificationPreferences($user),
            'security' => [
                'login_at' => $user->login_at,
                'registered_ip' => $user->registered_ip,
                'last_login_ip' => $user->last_login_ip,
                'last_seen_ip' => $user->last_seen_ip,
                'last_password_changed_at' => $user->last_password_changed_at,
                'session' => [
                    'token_id' => $token?->id,
                    'name' => $token?->name,
                    'issued_at' => $token?->created_at,
                    'expires_at' => $token?->expires_at,
                ],
            ],
        ]);
    }

    public function updateNotificationPreferences(Request $request)
    {
        $user = User::with('permissions')->find(auth()->id());

        if (!$user) {
            return $this->response(false, 'User not found', null, [
                'authenticated' => false,
            ]);
        }

        if (!$this->canManageFullSettings($user)) {
            return response()->json([
                'success' => false,
                'message' => 'You are only allowed to update your profile and security settings.',
            ], 403);
        }

        $validated = $request->validate([
            'notify_transactions' => ['required', 'boolean'],
            'notify_user_registrations' => ['required', 'boolean'],
            'notify_low_stock' => ['required', 'boolean'],
            'notify_expiry_alerts' => ['required', 'boolean'],
            'notify_security_alerts' => ['required', 'boolean'],
            'notify_browser' => ['required', 'boolean'],
        ]);

        $availableColumns = $this->availableUserColumns(array_keys($validated));
        if (count($availableColumns) !== count($validated)) {
            return $this->response(false, 'Notification preferences are not available until the latest database migration is applied.');
        }

        $this->safeUserUpdate($user, $validated);

        return $this->response(true, 'Notification preferences updated successfully', $this->notificationPreferences($user->fresh()));
    }

    public function revokeOtherSessions(Request $request)
    {
        $user = User::find(auth()->id());

        if (!$user) {
            return $this->response(false, 'User not found', null, [
                'authenticated' => false,
            ]);
        }

        $currentToken = $request->user()?->currentAccessToken();
        $deleted = $user->tokens()
            ->when($currentToken, fn ($query) => $query->where('id', '!=', $currentToken->id))
            ->delete();

        return $this->response(true, 'Other sessions revoked successfully', [
            'revoked_sessions' => $deleted,
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

        $this->safeUserUpdate($user, [
            'last_password_changed_at' => now(),
        ]);

        return $this->response(true, 'Password changed successfully');
    }
}
