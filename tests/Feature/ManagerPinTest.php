<?php
namespace Tests\Feature;

use App\Models\v1\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ManagerPinTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_pin_is_preserved_and_replacements_require_confirmation(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'status' => 'approved']);
        $manager = User::factory()->create(['branch_id' => $owner->branch_id, 'role' => 'admin', 'status' => 'approved', 'manager_pin_hash' => Hash::make('1234')]);
        $this->actingAs($owner, 'sanctum');
        $url = '/api/v1/user/'.$manager->user_id;
        $data = ['first_name' => 'Manager', 'last_name' => 'Test', 'email' => $manager->email, 'role' => 'admin'];
        $hash = $manager->manager_pin_hash;
        $this->putJson($url, $data)->assertOk()->assertJsonPath('data.has_manager_pin', true)
            ->assertJsonMissingPath('data.manager_pin_hash')->assertJsonMissingPath('data.manager_pin');
        $this->assertSame($hash, $manager->fresh()->manager_pin_hash);
        $this->putJson($url, [...$data, 'manager_pin' => '5678'])->assertUnprocessable()->assertJsonValidationErrors('manager_pin');
        $this->putJson($url, [...$data, 'manager_pin' => '5678', 'manager_pin_confirmation' => '5679'])->assertUnprocessable();
        $this->assertSame($hash, $manager->fresh()->manager_pin_hash);
        $this->putJson($url, [...$data, 'manager_pin' => '0567', 'manager_pin_confirmation' => '0567'])->assertOk();
        $this->assertTrue(Hash::check('0567', $manager->fresh()->manager_pin_hash));
        $this->assertFalse(Hash::check('1234', $manager->fresh()->manager_pin_hash));
        $this->putJson($url, [...$data, 'role' => 'staff'])->assertOk()->assertJsonPath('data.has_manager_pin', false);
        $this->assertNull($manager->fresh()->manager_pin_hash);
    }

    public function test_new_manager_needs_a_valid_confirmed_pin(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'status' => 'approved']);
        $this->actingAs($owner, 'sanctum');
        $data = ['first_name' => 'New', 'last_name' => 'Manager', 'email' => 'new-manager@example.test', 'role' => 'branch_manager',
            'branch_id' => $owner->branch_id, 'address' => 'Test address', 'password' => 'password123', 'password_confirmation' => 'password123'];
        $this->postJson('/api/v1/user', $data)->assertUnprocessable();
        $this->postJson('/api/v1/user', [...$data, 'manager_pin' => 'abc1', 'manager_pin_confirmation' => 'abc1'])->assertUnprocessable();
        $this->postJson('/api/v1/user', [...$data, 'manager_pin' => '1234', 'manager_pin_confirmation' => '1234'])->assertCreated()->assertJsonPath('data.has_manager_pin', true);
    }
}
