<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->updateOrInsert(
            ['permission_name' => 'users_all_branches'],
            ['description' => 'Can view users across all branches in the same company']
        );
    }

    public function down(): void
    {
        DB::table('user_permissions')
            ->whereIn('permission_id', function ($query) {
                $query->select('permission_id')
                    ->from('permissions')
                    ->where('permission_name', 'users_all_branches');
            })
            ->delete();

        DB::table('permissions')
            ->where('permission_name', 'users_all_branches')
            ->delete();
    }
};
