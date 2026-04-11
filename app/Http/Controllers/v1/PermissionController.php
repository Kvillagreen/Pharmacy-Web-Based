<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\v1\User;

class PermissionController extends Controller
{
    /**
     * Standard API response
     */
    private function response(bool $success, string $message, $data = null, int $status = 200)
    {
        return response()->json([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    /**
     * Get all permissions of a specific user
     */
    public function index(string $userId)
    {
        $user = User::with('permissions')->find($userId);

        if (!$user) {
            return $this->response(false, 'User not found', null, 404);
        }

        return $this->response(true, 'User permissions fetched successfully', [
            'user_id' => $user->user_id,
            'permissions' => $user->permissions,
        ]);
    }

    /**
     * Assign one or more permissions to a user
     * body:
     * {
     *   "user_id": 1,
     *   "permission_ids": [1,2,3]
     * }
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,user_id'],
            'permission_ids' => ['required', 'array', 'min:1'],
            'permission_ids.*' => ['integer', 'exists:permissions,permission_id'],
        ]);

        DB::beginTransaction();

        try {
            $user = User::findOrFail($validated['user_id']);

            $user->permissions()->syncWithoutDetaching($validated['permission_ids']);
            $user->load('permissions');

            DB::commit();

            return $this->response(true, 'Permissions assigned successfully', [
                'user_id' => $user->user_id,
                'permissions' => $user->permissions,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->response(false, 'Failed to assign permissions', [
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Replace all user permissions
     * body:
     * {
     *   "permission_ids": [1,2,3]
     * }
     *
     * You may also send:
     * {
     *   "permission_ids": []
     * }
     * to remove all permissions from the user.
     */
    public function update(Request $request, string $userId)
    {
        $validated = $request->validate([
            'permission_ids' => ['required', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,permission_id'],
        ]);

        DB::beginTransaction();

        try {
            $user = User::find($userId);

            if (!$user) {
                return $this->response(false, 'User not found', null, 404);
            }

            $user->permissions()->sync($validated['permission_ids']);
            $user->load('permissions');

            DB::commit();

            return $this->response(true, 'User permissions updated successfully', [
                'user_id' => $user->user_id,
                'permissions' => $user->permissions,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->response(false, 'Failed to update user permissions', [
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove one permission from a user
     * body:
     * {
     *   "permission_id": 2
     * }
     */
    public function destroy(Request $request, string $userId)
    {
        $validated = $request->validate([
            'permission_id' => ['required', 'integer', 'exists:permissions,permission_id'],
        ]);

        DB::beginTransaction();

        try {
            $user = User::find($userId);

            if (!$user) {
                return $this->response(false, 'User not found', null, 404);
            }

            $user->permissions()->detach($validated['permission_id']);
            $user->load('permissions');

            DB::commit();

            return $this->response(true, 'Permission removed from user successfully', [
                'user_id' => $user->user_id,
                'permissions' => $user->permissions,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->response(false, 'Failed to remove permission from user', [
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
