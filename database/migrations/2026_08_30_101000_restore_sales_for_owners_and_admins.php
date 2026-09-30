<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $salesPermissionId = DB::table('permissions')->where('permission_name', 'sales')->value('permission_id');
        if (!$salesPermissionId) return;

        $now = now();
        $rows = DB::table('users')
            ->whereIn('role', ['owner', 'admin'])
            ->pluck('user_id')
            ->map(fn ($userId) => [
                'user_id' => $userId,
                'permission_id' => $salesPermissionId,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

        if ($rows !== []) {
            DB::table('user_permissions')->upsert($rows, ['user_id', 'permission_id'], ['updated_at']);
        }
    }

    public function down(): void
    {
        $salesPermissionId = DB::table('permissions')->where('permission_name', 'sales')->value('permission_id');
        if ($salesPermissionId) {
            DB::table('user_permissions')
                ->where('permission_id', $salesPermissionId)
                ->whereIn('user_id', DB::table('users')->whereIn('role', ['owner', 'admin'])->select('user_id'))
                ->delete();
        }
    }
};
