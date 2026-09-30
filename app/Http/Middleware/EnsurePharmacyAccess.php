<?php

namespace App\Http\Middleware;

use App\Models\v1\Branch;
use App\Models\v1\Inventory;
use App\Models\v1\Transaction;
use App\Models\v1\User;
use App\Services\v1\RoleAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Enforce current role and tenant scope independently of client navigation/token age. */
class EnsurePharmacyAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->status === 'approved', 403, 'Account is not approved.');
        abort_unless(in_array($user->role, RoleAccess::USER_ROLES, true), 403, 'No pharmacy role is assigned.');
        $module = $request->segment(3);
        $manager = RoleAccess::managesCompany($user->role);
        $selfProfile = $module === 'user' && in_array($request->method(), ['PUT', 'PATCH'], true)
            && (int) $request->route('user') === (int) $user->user_id;
        $required = match ($module) {
            'dashboard' => 'dashboard', 'medicine' => 'inventory', 'fefo' => 'fefo',
            'controlled-drugs' => 'drugs', 'reports' => 'reports', 'sms' => 'sms',
            'transaction' => 'sales', 'user', 'permissions' => 'users',
            'settings', 'change-password' => 'settings',
            default => null,
        };
        if ($required && !$selfProfile) {
            abort_unless($user->hasPermission($required), 403, 'Module access denied.');
        }
        if ($selfProfile && !$manager) {
            abort_if($request->hasAny(['role', 'status', 'manager_pin', 'manager_pin_hash', 'permissions', 'branch_id']), 403, 'Only profile information may be changed.');
        }
        if ($module === 'company' || ($module === 'branch' && !$request->isMethod('GET'))) {
            $ownBranchUpdate = $module === 'branch' && $user->role === 'branch_manager'
                && in_array($request->method(), ['PUT', 'PATCH'], true)
                && (int) $request->route('branch') === (int) $user->branch_id;
            abort_unless($manager || $ownBranchUpdate, 403, 'Settings access denied.');
        }

        // Authentication and personal settings do not need inventory scope queries.
        if (in_array($module, ['auth-user', 'logout', 'settings', 'change-password'], true)) {
            return $next($request);
        }
        $companyId = (int) $user->branch?->company_id;
        abort_unless($companyId > 0, 403, 'No company is assigned to this account.');
        $branches = Branch::query()->where('company_id', $companyId)
            ->when(!$manager, fn ($q) => $q->where('branch_id', $user->branch_id))
            ->pluck('branch_id')->map(fn ($id) => (int) $id)->all();
        $requestedBranch = (int) $request->input('branch_id', 0);
        if ($requestedBranch > 0) {
            abort_unless(in_array($requestedBranch, $branches, true), 403, 'Branch access denied.');
        }
        if ($request->filled('company_id')) {
            abort_unless((int) $request->input('company_id') === $companyId, 403, 'Company access denied.');
        }
        $request->merge(['company_id' => $companyId, 'branch_id' => $manager ? $requestedBranch : (int) $user->branch_id]);
        $request->attributes->set('allowed_branch_ids', $branches);
        $routeId = $request->route($module) ?? $request->route('id');
        if ($module === 'branch' && $routeId !== null) {
            // GET branch/{id} lists branches of a company; writes address a branch.
            abort_unless($request->isMethod('GET') ? (int) $routeId === $companyId : in_array((int) $routeId, $branches, true), 403, 'Branch access denied.');
        }
        if ($module === 'company' && $routeId !== null) {
            abort_unless((int) $routeId === $companyId, 403, 'Company access denied.');
        }
        if ($module === 'user' && $routeId !== null) {
            $target = $request->routeIs('users.restore') ? User::withTrashed()->findOrFail($routeId) : User::findOrFail($routeId);
            abort_unless(in_array((int) $target->branch_id, $branches, true), 403, 'User access denied.');
        }
        if ($request->route('branch_id') !== null) {
            abort_unless(in_array((int) $request->route('branch_id'), $branches, true), 403, 'Branch access denied.');
        }
        if ($module === 'transaction' && $routeId !== null) {
            $transaction = Transaction::findOrFail($routeId);
            abort_unless(in_array((int) $transaction->branch_id, $branches, true), 403, 'Transaction access denied.');
        }
        if ($module === 'medicine' && $routeId !== null) {
            $inventory = Inventory::where('medicine_id', $routeId);
            abort_unless((clone $inventory)->whereIn('branch_id', $branches)->exists(), 403, 'Medicine access denied.');
            if (!$request->isMethod('GET')) {
                abort_if((clone $inventory)->whereNotIn('branch_id', $branches)->exists(), 403, 'This medicine is shared with another branch.');
            }
        }
        if ($request->filled('inventory_id')) {
            abort_unless(Inventory::where('inventory_id', $request->input('inventory_id'))->whereIn('branch_id', $branches)->exists(), 403, 'Inventory access denied.');
        }
        $batchId = $request->route('batch') ?? ($module === 'fefo' ? $routeId : null);
        if ($batchId !== null) {
            $inventory = Inventory::where('batch_id', $batchId);
            abort_unless((clone $inventory)->whereIn('branch_id', $branches)->exists(), 403, 'Batch access denied.');
            if (!$request->isMethod('GET')) {
                abort_if((clone $inventory)->whereNotIn('branch_id', $branches)->exists(), 403, 'This batch also belongs to another branch.');
            }
        }
        $response = $next($request);
        if (!$request->isMethodCacheable() && $response->isSuccessful()
            && in_array($module, ['transaction', 'medicine', 'fefo', 'controlled-drugs', 'branch', 'company', 'reports'], true)) {
            if (in_array($module, ['branch', 'company'], true)) {
                \Illuminate\Support\Facades\Cache::forget('public_branch_list_v2');
                \Illuminate\Support\Facades\Cache::forget('superadmin_companies');
                \Illuminate\Support\Facades\Cache::forget('superadmin_analytics');
            }
            \Illuminate\Support\Facades\Cache::put('dashboard_version_'.$companyId, (string) \Illuminate\Support\Str::uuid(), 86400);
        }
        return $response;
    }
}
