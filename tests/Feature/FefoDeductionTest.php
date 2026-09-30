<?php

namespace Tests\Feature;

use App\Models\v1\User;
use App\Models\v1\Branch;
use App\Models\v1\Medicine;
use App\Models\v1\Batch;
use App\Models\v1\Inventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FefoDeductionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test FEFO (First Expire First Out) deduction logic in Transactions (TC-084).
     */
    public function test_inventory_is_deducted_using_fefo_logic()
    {
        $user = User::factory()->create(['role' => 'admin']);
        $branch = Branch::factory()->create(['status' => 'active']);
        
        $medicine = Medicine::factory()->create([
            'price' => 100.00,
            'is_dangerous' => false,
            'needs_protection' => false
        ]);
        
        // Batch A expires sooner (in 15 months)
        $batchA = Batch::factory()->create([
            'expiry_date' => now()->addMonths(15)->format('Y-m-d')
        ]);
        $inventoryA = Inventory::factory()->create([
            'branch_id' => $branch->branch_id,
            'medicine_id' => $medicine->medicine_id,
            'batch_id' => $batchA->batch_id,
            'stocks' => 5
        ]);

        // Batch B expires later (in 24 months)
        $batchB = Batch::factory()->create([
            'expiry_date' => now()->addMonths(24)->format('Y-m-d')
        ]);
        $inventoryB = Inventory::factory()->create([
            'branch_id' => $branch->branch_id,
            'medicine_id' => $medicine->medicine_id,
            'batch_id' => $batchB->batch_id,
            'stocks' => 10
        ]);

        // Request 8 items. It should take all 5 from Batch A, and 3 from Batch B.
        $payload = [
            'user_id' => $user->user_id,
            'branch_id' => $branch->branch_id,
            'transaction_type' => 'regular',
            'total_amount' => 800.00,
            'sub_total' => 800.00,
            'change' => 200.00,
            'used_amount' => 1000.00,
            'payment_method' => 'Cash',
            'discount' => 0,
            'items' => [
                [
                    'medicine_id' => $medicine->medicine_id,
                    'quantity' => 8
                ]
            ]
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/transaction', $payload);

        $response->assertStatus(201);
        
        $this->assertEquals(0, $inventoryA->fresh()->stocks); // 5 - 5 = 0
        $this->assertEquals(7, $inventoryB->fresh()->stocks); // 10 - 3 = 7
    }
}
