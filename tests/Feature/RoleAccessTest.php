<?php

namespace Tests\Feature;

use App\Models\v1\Branch;
use App\Models\v1\User;
use App\Services\v1\RoleAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public static function roles(): array
    {
        return [['staff'], ['pharmacist'], ['branch_manager'], ['owner'], ['admin']];
    }

    #[DataProvider('roles')]
    public function test_login_session_settings_and_modules_agree_for_every_role(string $role): void
    {
        $user = User::factory()->create(['role' => $role, 'status' => 'approved']);
        // No permission pivot rows: existing accounts still receive the specified role modules.
        $login = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'admin123'])
            ->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.permissions', RoleAccess::permissions($role));
        $this->withToken($login->json('token'));
        $this->postJson('/api/v1/auth-user')->assertOk()
            ->assertJsonPath('data.permissions', RoleAccess::permissions($role));
        $this->getJson('/api/v1/settings')->assertOk()
            ->assertJsonPath('data.access.can_manage_all_settings', in_array($role, ['admin', 'owner'], true));
        foreach (['transaction', 'medicine', 'fefo', 'controlled-drugs', 'sms/orders', 'header/notifications'] as $endpoint) {
            $this->getJson('/api/v1/'.$endpoint)->assertOk();
        }
        foreach (['dashboard' => 'dashboard', 'reports' => 'reports', 'user' => 'users'] as $endpoint => $permission) {
            $this->getJson('/api/v1/'.$endpoint)->assertStatus(in_array($permission, RoleAccess::permissions($role), true) ? 200 : 403);
        }
        $this->putJson('/api/v1/settings/notifications', [
            'notify_transactions' => true, 'notify_user_registrations' => false,
            'notify_low_stock' => true, 'notify_expiry_alerts' => true,
            'notify_security_alerts' => true, 'notify_browser' => false,
        ])->assertOk()->assertJsonPath('success', true);
        $this->putJson('/api/v1/user/'.$user->user_id, [
            'first_name' => 'Updated', 'last_name' => $user->last_name, 'email' => $user->email,
        ])->assertOk()->assertJsonPath('data.first_name', 'Updated');
    }

    public function test_staff_cannot_escalate_role_or_edit_company_or_branch(): void
    {
        $user = User::factory()->create(['role' => 'staff', 'status' => 'approved']);
        $this->actingAs($user, 'sanctum');
        $this->putJson('/api/v1/user/'.$user->user_id, ['role' => 'admin'])->assertForbidden();
        $this->putJson('/api/v1/company/'.$user->branch->company_id, [])->assertForbidden();
        $this->putJson('/api/v1/branch/'.$user->branch_id, [])->assertForbidden();
        $this->postJson('/api/v1/branch', [])->assertForbidden();
    }

    public function test_manager_is_limited_to_assigned_branch_and_cannot_create_or_delete_branches(): void
    {
        $user = User::factory()->create(['role' => 'branch_manager', 'status' => 'approved']);
        $other = Branch::factory()->create(['company_id' => $user->branch->company_id]);
        $this->actingAs($user, 'sanctum');
        foreach (['dashboard', 'medicine', 'fefo', 'reports', 'transaction', 'controlled-drugs', 'sms/orders'] as $endpoint) {
            $this->getJson('/api/v1/'.$endpoint.'?branch_id='.$other->branch_id)->assertForbidden();
        }
        $this->getJson('/api/v1/branch/'.$user->branch->company_id)->assertOk()->assertJsonCount(1, 'data.branches');
        $this->putJson('/api/v1/branch/'.$user->branch_id, [
            'company_id' => $user->branch->company_id, 'branch_name' => 'Updated Assigned Branch',
            'branch_address' => 'Updated branch address', 'branch_contact' => '09123456789',
            'theme_key' => 'ocean', 'status' => 'active',
        ])->assertOk()->assertJsonPath('data.branch_name', 'Updated Assigned Branch');
        $this->putJson('/api/v1/branch/'.$other->branch_id, [])->assertForbidden();
        $this->postJson('/api/v1/branch', [])->assertForbidden();
        $this->deleteJson('/api/v1/branch/'.$user->branch_id)->assertForbidden();
        $this->putJson('/api/v1/company/'.$user->branch->company_id, [])->assertForbidden();
    }

    public function test_owner_has_admin_modules_but_cannot_read_another_company(): void
    {
        $user = User::factory()->create(['role' => 'owner', 'status' => 'approved']);
        $other = Branch::factory()->create();
        $this->assertSame(RoleAccess::permissions('admin'), $user->effectivePermissions());
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard?company_id='.$other->company_id)->assertForbidden();
        $this->getJson('/api/v1/branch/'.$other->company_id)->assertForbidden();
    }
}
