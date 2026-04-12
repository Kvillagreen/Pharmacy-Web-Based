<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\v1\LoginRequest;
use App\Http\Requests\v1\MobileRegisterRequest;
use App\Models\v1\Batch;
use App\Models\v1\Branch;
use App\Models\v1\Company;
use App\Models\v1\Inventory;
use App\Models\v1\MobileUser;
use App\Models\v1\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class MobileAuthController extends Controller
{
    private function response($success, $message, $data = null, $extra = [])
    {
        return response()->json(array_merge([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], $extra));
    }

    private function notificationPreferences(MobileUser $user): array
    {
        return [
            'notify_transactions' => (bool) $user->notify_transactions,
            'notify_low_stock' => (bool) $user->notify_low_stock,
            'notify_expiry_alerts' => (bool) $user->notify_expiry_alerts,
            'notify_security_alerts' => (bool) $user->notify_security_alerts,
            'notify_browser' => (bool) $user->notify_browser,
        ];
    }

    private function formatMobileUser(MobileUser $user): array
    {
        return [
            'user_id' => $user->mobile_user_id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'address' => $user->address,
            'company_id' => $user->company_id,
            'company_name' => $user->company?->company_name,
            'branch_id' => $user->branch_id,
            'branch_name' => $user->branch?->branch_name,
            'status' => $user->status,
            'permissions' => ['mobile_user'],
        ];
    }

    public function companies()
    {
        $companies = Company::query()
            ->whereHas('branches', fn ($query) => $query->where('status', 'active'))
            ->with(['branches' => fn ($query) => $query->where('status', 'active')->orderBy('branch_name')])
            ->orderBy('company_name')
            ->get()
            ->map(function (Company $company) {
                return [
                    'company_id' => (int) $company->company_id,
                    'company_name' => $company->company_name,
                    'company_email' => $company->company_email,
                    'branches' => $company->branches->map(fn (Branch $branch) => [
                        'branch_id' => (int) $branch->branch_id,
                        'branch_name' => $branch->branch_name,
                        'branch_address' => $branch->branch_address,
                        'branch_contact' => $branch->branch_contact,
                    ])->values(),
                ];
            })
            ->values();

        return $this->response(true, 'Companies loaded successfully', $companies);
    }

    public function register(MobileRegisterRequest $request)
    {
        $validated = $request->validated();

        $branch = null;
        if (!empty($validated['branchId'])) {
            $branch = Branch::query()->where('branch_id', $validated['branchId'])->where('status', 'active')->first();

            if (!$branch) {
                return $this->response(false, 'Selected branch is not available.');
            }

            if (!empty($validated['companyId']) && (int) $validated['companyId'] !== (int) $branch->company_id) {
                return $this->response(false, 'The selected branch does not belong to the selected company.');
            }
        }

        $companyId = !empty($validated['companyId'])
            ? (int) $validated['companyId']
            : (int) ($branch?->company_id ?? 0);

        $mobileUser = MobileUser::create([
            'company_id' => $companyId ?: null,
            'branch_id' => !empty($validated['branchId']) ? (int) $validated['branchId'] : null,
            'first_name' => $validated['firstName'],
            'last_name' => $validated['lastName'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'address' => $validated['address'] ?? null,
            'status' => 'approved',
            'registered_ip' => $request->ip(),
            'last_seen_ip' => $request->ip(),
        ]);

        $mobileUser->load(['company', 'branch']);

        $token = $mobileUser->createToken('mobile_auth_token', ['mobile_user'], Carbon::now()->addHours(24));

        return $this->response(true, 'Mobile account created successfully.', $this->formatMobileUser($mobileUser), [
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at ?? Carbon::now()->addHours(24),
        ]);
    }

    public function login(LoginRequest $request)
    {
        $validated = $request->validated();
        $key = 'mobile|' . Str::lower($validated['email']) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return $this->response(false, 'Too many login attempts. Try again later.');
        }

        $mobileUser = MobileUser::with(['company', 'branch'])
            ->where('email', $validated['email'])
            ->first();

        if (!$mobileUser || !Hash::check($validated['password'], $mobileUser->password)) {
            RateLimiter::hit($key, 60);
            return $this->response(false, 'Invalid credentials.');
        }

        if ($mobileUser->status !== 'approved') {
            return $this->response(false, 'Your mobile account is not available right now.');
        }

        RateLimiter::clear($key);

        $mobileUser->tokens()->delete();
        $mobileUser->update([
            'login_at' => now(),
            'last_login_ip' => $request->ip(),
            'last_seen_ip' => $request->ip(),
        ]);

        $token = $mobileUser->createToken('mobile_auth_token', ['mobile_user'], Carbon::now()->addHours(24));

        return $this->response(true, 'Login successful.', $this->formatMobileUser($mobileUser->fresh(['company', 'branch'])), [
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at ?? Carbon::now()->addHours(24),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        return $this->response(true, 'Logged out successfully.');
    }

    public function settings(Request $request)
    {
        /** @var MobileUser|null $user */
        $user = $request->user();

        if (!$user) {
            return $this->response(false, 'User not found.');
        }

        $user->load(['company', 'branch']);

        return $this->response(true, 'Settings loaded successfully.', [
            'profile' => [
                'user_id' => $user->mobile_user_id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'address' => $user->address,
                'branch_id' => $user->branch_id,
                'branch_name' => $user->branch?->branch_name,
                'company_id' => $user->company_id,
                'company_name' => $user->company?->company_name,
            ],
            'notifications' => $this->notificationPreferences($user),
        ]);
    }

    public function updateProfile(Request $request)
    {
        /** @var MobileUser|null $user */
        $user = $request->user();

        if (!$user) {
            return $this->response(false, 'User not found.');
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:mobile_users,email,' . $user->mobile_user_id . ',mobile_user_id'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $user->update($validated);

        return $this->response(true, 'Profile updated successfully.', $this->formatMobileUser($user->fresh(['company', 'branch'])));
    }

    public function changePassword(Request $request)
    {
        /** @var MobileUser|null $user */
        $user = $request->user();

        if (!$user) {
            return $this->response(false, 'User not found.');
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'different:current_password'],
            'new_password_confirmation' => ['required', 'same:new_password'],
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            return $this->response(false, 'Current password is incorrect.');
        }

        $user->update([
            'password' => Hash::make($validated['new_password']),
            'last_password_changed_at' => now(),
        ]);

        return $this->response(true, 'Password changed successfully.');
    }

    public function updateNotificationPreferences(Request $request)
    {
        /** @var MobileUser|null $user */
        $user = $request->user();

        if (!$user) {
            return $this->response(false, 'User not found.');
        }

        $validated = $request->validate([
            'notify_transactions' => ['required', 'boolean'],
            'notify_low_stock' => ['required', 'boolean'],
            'notify_expiry_alerts' => ['required', 'boolean'],
            'notify_security_alerts' => ['required', 'boolean'],
            'notify_browser' => ['required', 'boolean'],
        ]);

        $user->update($validated);

        return $this->response(true, 'Notification preferences updated successfully.', $this->notificationPreferences($user->fresh()));
    }

    public function headerNotifications(Request $request)
    {
        $companyId = (int) $request->input('company_id', 0);
        $branchId = (int) $request->input('branch_id', 0);

        $branchScope = Branch::query()->where('status', 'active');

        if ($branchId > 0) {
            $branchScope->where('branch_id', $branchId);
        } elseif ($companyId > 0) {
            $branchScope->where('company_id', $companyId);
        } else {
            return $this->response(true, 'Notifications fetched successfully.', [
                'notifications' => [],
                'unread_count' => 0,
            ]);
        }

        $scopeBranchIds = $branchScope->pluck('branch_id');

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
                    'branch_name' => $transaction->branch?->branch_name,
                    'created_at' => $transaction->created_at,
                ];
            });

        $lowStockNotifications = Inventory::query()
            ->join('medicines', 'inventories.medicine_id', '=', 'medicines.medicine_id')
            ->join('branches', 'inventories.branch_id', '=', 'branches.branch_id')
            ->whereIn('inventories.branch_id', $scopeBranchIds)
            ->whereColumn('medicines.stocks', '<=', 'medicines.reorder_level')
            ->select([
                'medicines.medicine_name',
                'medicines.stocks',
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

        $notifications = $transactionNotifications
            ->concat($lowStockNotifications)
            ->concat($expiryNotifications)
            ->sortByDesc('created_at')
            ->take(12)
            ->values();

        return $this->response(true, 'Notifications fetched successfully.', [
            'notifications' => $notifications,
            'unread_count' => $notifications->count(),
        ]);
    }
}
