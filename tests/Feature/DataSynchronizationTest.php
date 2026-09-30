<?php
namespace Tests\Feature;

use App\Models\v1\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DataSynchronizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_branches_reflect_direct_database_changes_without_stale_cache(): void
    {
        $branch = \App\Models\v1\Branch::factory()->create(['branch_name' => 'Original branch']);
        $this->postJson('/api/v1/branch-public')->assertOk()->assertJsonFragment(['branch_name' => 'Original branch']);
        DB::table('branches')->where('branch_id', $branch->branch_id)->update(['branch_name' => 'Renamed branch']);
        $this->postJson('/api/v1/branch-public')->assertOk()->assertJsonFragment(['branch_name' => 'Renamed branch'])
            ->assertJsonMissing(['branch_name' => 'Original branch']);
        DB::table('branches')->where('branch_id', $branch->branch_id)->update(['status' => 'inactive']);
        $this->postJson('/api/v1/branch-public')->assertOk()->assertJsonMissing(['branch_id' => $branch->branch_id]);
    }

    public function test_api_writes_persist_and_direct_database_changes_appear_in_reads(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'status' => 'approved']);
        $this->actingAs($owner, 'sanctum');
        $payload = ['first_name' => 'Sync', 'last_name' => 'Test', 'email' => 'sync@example.test',
            'password' => 'test-password', 'password_confirmation' => 'test-password',
            'branch_id' => $owner->branch_id, 'role' => 'staff', 'address' => 'Test address'];
        $id = $this->postJson('/api/v1/user', $payload)->assertCreated()->json('data.user_id');
        $this->assertDatabaseHas('users', ['user_id' => $id, 'first_name' => 'Sync']);
        DB::table('users')->where('user_id', $id)->update(['first_name' => 'Database change']);
        $this->getJson('/api/v1/user?search=sync@example.test')->assertOk()
            ->assertJsonPath('data.0.first_name', 'Database change');
        $this->deleteJson('/api/v1/user/'.$id)->assertOk();
        $this->assertSoftDeleted('users', ['user_id' => $id]);
        $this->getJson('/api/v1/user?search=sync@example.test')->assertOk()->assertJsonCount(0, 'data');
        DB::table('users')->where('user_id', $id)->update(['deleted_at' => null, 'status' => 'approved']);
        $this->getJson('/api/v1/user?search=sync@example.test')->assertOk()->assertJsonCount(1, 'data');
        DB::table('users')->where('user_id', $id)->delete();
        $this->getJson('/api/v1/user?search=sync@example.test')->assertOk()->assertJsonCount(0, 'data');
    }
}
