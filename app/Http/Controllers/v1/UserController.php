<?php

namespace App\Http\Controllers\v1;

use App\Models\v1\Permission;
use App\Models\v1\User;
use App\Models\v1\Branch;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\v1\UserQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private function formatUser(User $user): array
    {
        return [
            'user_id' => $user->user_id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'status' => $user->status,
            'role' => $user->role,
            'branch_id' => $user->branch_id,
            'branch_name' => $user->branch?->branch_name,
            'branch_address' => $user->branch?->branch_address,
            'address' => $user->address ?? null,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
            'login_at' => $user->login_at,
            'branch' => $user->branch,
            'company' => $user->branch?->company,
            'permissions' => $user->permissions,
            'permission_names' => $user->permissions
                ->pluck('permission_name')
                ->values(),
        ];
    }

    public function index(Request $request)
    {
        $perPage = (int) $request->input('per_page', 9);
        $isExport = filter_var($request->input('export', false), FILTER_VALIDATE_BOOLEAN);

        $query = User::with(['branch.company', 'permissions'])
            ->whereIn('status', ['approved', 'pending', 'rejected']);

        // Filter by company_id
        if ($request->filled('company_id')) {
            $query->whereHas('branch', function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            });
        }

        // Apply filters
        if ($request->hasAny([
            'search',
            'sort',
            'filter',
            'user_id',
            'first_name',
            'last_name',
            'status',
            'role',
            'branch_id'
        ])) {
            $filter = new UserQuery();
            $query = $filter->apply($request, $query);
        } else {
            $query->orderBy('user_id', 'desc');
        }

        $queryOnly = User::with(['branch.company', 'permissions'])
            ->whereIn('status', ['approved', 'pending', 'rejected']);

        if ($request->filled('company_id')) {
            $queryOnly->whereHas('branch', function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            });
        }

        $totalUsers = (clone $queryOnly)->count();

        $adminCount = (clone $queryOnly)
            ->where('role', 'admin')
            ->count();

        $managerCount = (clone $queryOnly)
            ->where('role', 'manager')
            ->count();

        $activeCount = (clone $queryOnly)
            ->where('status', 'approved')
            ->count();

        if ($isExport) {
            $users = $query->get();
            $formattedUsers = $users->map(fn($user) => $this->formatUser($user));

            return response()->json([
                'success' => true,
                'data' => $formattedUsers,
                'stats' => [
                    'total_users' => $totalUsers,
                    'admin' => $adminCount,
                    'manager' => $managerCount,
                    'active' => $activeCount,
                ],
            ]);
        }

        $users = $query->paginate($perPage);

        $formattedUsers = collect($users->items())->map(fn($user) => $this->formatUser($user));

        return response()->json([
            'success' => true,
            'data' => $formattedUsers,
            'stats' => [
                'total_users' => $totalUsers,
                'admin' => $adminCount,
                'manager' => $managerCount,
                'active' => $activeCount,
            ],
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function show(string $id)
    {
        $user = User::with(['branch.company', 'permissions'])->find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatUser($user)
        ]);
    }

    public function store(Request $request)
    {
        $authUser = auth()->user();

        if (!$authUser) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'branch_id' => ['required', 'integer', 'exists:branches,branch_id'],
            'role' => ['required', Rule::in(['pharmacist', 'admin', 'inventory', 'user', 'manager'])],
            'address' => ['required', 'string', 'max:500'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,permission_id'],
        ]);

        $authCompanyId = $authUser->branch?->company_id;

        if (!$authCompanyId) {
            return response()->json([
                'success' => false,
                'message' => 'Authenticated user is not assigned to a valid company'
            ], 422);
        }

        $branch = Branch::query()
            ->where('branch_id', $validated['branch_id'])
            ->where('company_id', $authCompanyId)
            ->where('status', 'active')
            ->first();

        if (!$branch) {
            return response()->json([
                'success' => false,
                'message' => 'Selected branch is not available for your company'
            ], 422);
        }

        DB::beginTransaction();

        try {
            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'branch_id' => $branch->branch_id,
                'role' => $validated['role'],
                'address' => $validated['address'],
                'status' => 'approved',
            ]);

            $user->permissions()->sync($validated['permission_ids'] ?? []);

            $user->load(['branch.company', 'permissions']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'User created successfully',
                'data' => $this->formatUser($user)
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create user',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, string $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->user_id, 'user_id')
            ],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $user->update($validated);

        $user->load(['branch.company', 'permissions']);

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data' => $this->formatUser($user)
        ]);
    }

    public function destroy(string $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        if ((int) auth()->id() === (int) $user->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account'
            ], 422);
        }

        $user->update(['status' => 'deleted']);
        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully'
        ]);
    }

    public function updateUserStatus(string $id, string $status)
    {
        if (!in_array($status, ['approved', 'pending', 'rejected'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid user status'
            ], 422);
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $user->update(['status' => $status]);

        return response()->json([
            'success' => true,
            'message' => 'User status updated successfully'
        ]);
    }

    public function updateUserBranch(string $id, string $branch_id)
    {
        $user = User::find($id);
        $branch = Branch::find($branch_id);
        if (!$user || !$branch) {
            return response()->json([
                'success' => false,
                'message' => 'User or branch not found'
            ], 404);
        }
        if($user->branch_id == $branch_id) {
            return response()->json([
                'success' => false,
                'message' => 'User is already assigned to this branch'
            ], 400);
        }

        $user->update(['branch_id' => $branch_id]);

        return response()->json([
            'success' => true,
            'message' => 'User branch updated successfully'
        ]);
    }

    public function permissionOptions()
    {
        $permissions = Permission::query()
            ->orderBy('permission_name')
            ->get(['permission_id', 'permission_name', 'description']);

        return response()->json([
            'success' => true,
            'data' => $permissions,
        ]);
    }

    public function userPermissions(string $id)
    {
        $user = User::with('permissions')->find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $permissions = Permission::query()
            ->orderBy('permission_name')
            ->get(['permission_id', 'permission_name', 'description']);

        return response()->json([
            'success' => true,
            'data' => [
                'user_id' => $user->user_id,
                'permission_ids' => $user->permissions->pluck('permission_id')->values(),
                'permissions' => $permissions,
            ]
        ]);
    }

    public function updateUserPermissions(Request $request, string $id)
    {
        $validated = $request->validate([
            'permission_ids' => ['required', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,permission_id'],
        ]);

        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        DB::beginTransaction();

        try {
            $user->permissions()->sync($validated['permission_ids']);
            $user->load(['branch.company', 'permissions']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'User permissions updated successfully',
                'data' => $this->formatUser($user)
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update user permissions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
