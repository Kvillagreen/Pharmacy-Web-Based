<?php
namespace Tests\Feature;
use App\Models\v1\{User, Permission};
use App\Services\v1\RoleAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class AutomaticRoleAssignmentTest extends TestCase {
    use RefreshDatabase;
    public function test_selected_role_assigns_modules_and_cannot_be_overridden(): void {
        foreach(RoleAccess::permissions('admin') as $name) Permission::firstOrCreate(['permission_name'=>$name]);
        $owner=User::factory()->create(['role'=>'owner','status'=>'approved']);$this->actingAs($owner,'sanctum');
        $policies=$this->getJson('/api/v1/user/permissions/options')->assertOk();
        foreach(RoleAccess::USER_ROLES as $role){
            $policies->assertJsonPath('role_access.'.$role.'.modules',RoleAccess::permissions($role));
            $created=$this->postJson('/api/v1/user',[
                'first_name'=>'Role','last_name'=>'Fixture','email'=>$role.'@example.test',
                'password'=>'Test-only-password','password_confirmation'=>'Test-only-password',
                'branch_id'=>$owner->branch_id,'role'=>$role,'address'=>'Fixture address',
                'manager_pin'=>'5678','manager_pin_confirmation'=>'5678',
                'permission_ids'=>Permission::pluck('permission_id')->all(),
            ])->assertCreated()->assertJsonPath('data.permission_names',RoleAccess::permissions($role));
            $target=User::findOrFail($created->json('data.user_id'));
            $this->assertEqualsCanonicalizing(RoleAccess::permissions($role),$target->permissions()->pluck('permission_name')->all());
            $this->putJson('/api/v1/user/'.$target->user_id.'/permissions',['permission_ids'=>[]])->assertUnprocessable();
            $this->putJson('/api/v1/user/'.$target->user_id,[
                'first_name'=>$target->first_name,'last_name'=>$target->last_name,'email'=>$target->email,'role'=>'staff',
            ])->assertOk()->assertJsonPath('data.permission_names',RoleAccess::permissions('staff'));
            $this->assertEqualsCanonicalizing(RoleAccess::permissions('staff'),$target->permissions()->pluck('permission_name')->all());
            $this->assertNull($target->fresh()->manager_pin_hash);
        }
    }
    public function test_stale_permission_rows_do_not_grant_staff_extra_access(): void {
        $user=User::factory()->create(['role'=>'staff','status'=>'approved']);$permission=Permission::firstOrCreate(['permission_name'=>'users']);$user->permissions()->sync([$permission->permission_id]);$this->actingAs($user,'sanctum');
        foreach(['user','reports','dashboard'] as $module) $this->getJson('/api/v1/'.$module)->assertForbidden();
        $this->postJson('/api/v1/auth-user')->assertOk()->assertJsonPath('data.permissions',RoleAccess::permissions('staff'));
    }
}
