<?php

namespace Tests\Feature;

use App\Models\v1\User;
use App\Models\v1\Branch;
use App\Models\v1\Medicine;
use App\Models\v1\Inventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlledDrugValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test transaction logic rejects dangerous drugs without required details (TC-087).
     */
    public function test_reject_dangerous_drug_without_patient_details()
    {
        $user = User::factory()->create(['role' => 'admin']);
        $branch = Branch::factory()->create(['status' => 'active']);
        
        $medicine = Medicine::factory()->create([
            'price' => 100.00,
            'is_dangerous' => true, // Dangerous drug!
            'needs_protection' => false
        ]);
        
        $inventory = Inventory::factory()->create([
            'branch_id' => $branch->branch_id,
            'medicine_id' => $medicine->medicine_id,
            'stocks' => 50
        ]);

        $payload = [
            'user_id' => $user->user_id,
            'branch_id' => $branch->branch_id,
            'transaction_type' => 'dangerous',
            'total_amount' => 100.00,
            'sub_total' => 100.00,
            'change' => 0.00,
            'used_amount' => 100.00,
            'payment_method' => 'Cash',
            'discount' => 0,
            'items' => [
                [
                    'medicine_id' => $medicine->medicine_id,
                    'inventory_id' => $inventory->inventory_id,
                    'quantity' => 1
                ]
            ],
            // Missing patient_name and other required fields intentionally
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/transaction', $payload);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'success' => false,
            // Assert that the first validation error is returned
            'message' => 'Patient full name is required for prescribed or dangerous drug transactions.'
        ]);
    }
}
