<?php

namespace Tests\Unit;

use App\Models\v1\User;
use App\Models\v1\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPermissionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that hasPermission returns true when a user has the permission.
     */
    public function test_user_has_permission()
    {
        // Given
        $user = User::factory()->create([
            'role' => 'admin',
        ]);
        
        $permission = Permission::factory()->create([
            'permission_name' => 'sales',
        ]);
        
        $user->permissions()->attach($permission->permission_id);

        // When
        $hasPermission = $user->hasPermission('sales');

        // Then
        $this->assertTrue($hasPermission);
    }

    /**
     * Test that hasPermission returns false when a user lacks the permission.
     */
    public function test_user_lacks_permission()
    {
        // Given
        $user = User::factory()->create([
            'role' => 'branch_manager',
        ]);
        
        $permission = Permission::factory()->create([
            'permission_name' => 'settings',
        ]);
        
        // Notice we do not attach the permission here

        // When
        $hasPermission = $user->hasPermission('settings');

        // Then
        $this->assertFalse($hasPermission);
    }
}
