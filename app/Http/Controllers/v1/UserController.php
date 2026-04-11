<?php

namespace App\Http\Controllers\v1;

use App\Models\v1\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\v1\UserQuery;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->input('per_page', 9);

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

        $users = $query->paginate($perPage);

        $formattedUsers = collect($users->items())->map(function ($user) {
            return [
                'user_id' => $user->user_id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'status' => $user->status,
                'role' => $user->role,
                'branch_id' => $user->branch_id,
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
        });

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
            'data' => [
                'user_id' => $user->user_id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'status' => $user->status,
                'role' => $user->role,
                'branch_id' => $user->branch_id,
                'address' => $user->address ?? null,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
                'branch' => $user->branch,
                'company' => $user->branch?->company,
                'permissions' => $user->permissions,
                'permission_names' => $user->permissions
                    ->pluck('permission_name')
                    ->values(),
            ]
        ]);
    }

    public function store(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Store method not implemented yet.'
        ], 501);
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
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->user_id, 'user_id')
            ],
            'status' => ['sometimes', Rule::in(['approved', 'pending', 'rejected'])],
            'role' => ['sometimes', 'string', 'max:100'],
            'branch_id' => ['sometimes', 'integer', 'exists:branches,branch_id'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $user->update($validated);

        $user->load(['branch.company', 'permissions']);

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data' => [
                'user_id' => $user->user_id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'status' => $user->status,
                'role' => $user->role,
                'branch_id' => $user->branch_id,
                'address' => $user->address ?? null,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
                'branch' => $user->branch,
                'company' => $user->branch?->company,
                'permissions' => $user->permissions,
                'permission_names' => $user->permissions
                    ->pluck('permission_name')
                    ->values(),
            ]
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

        $user->update(['status' => 'deleted']);
        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully'
        ]);
    }
}
