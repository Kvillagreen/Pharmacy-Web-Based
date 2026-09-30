<?php

namespace Tests\Feature;

use App\Models\v1\User;
use App\Models\v1\Branch;
use App\Models\v1\Medicine;
use App\Models\v1\Inventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionDiscountTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test SC/PWD discount requires an ID number (TC-043).
     */
    public function test_scpwd_discount_requires_id_number()
    {
        $user = User::factory()->create(['role' => 'admin']);
        $branch = Branch::factory()->create(['status' => 'active']);
        
        $medicine = Medicine::factory()->create([
            'price' => 100.00,
            'is_dangerous' => false,
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
            'transaction_type' => 'regular',
            'total_amount' => 80.00,
            'sub_total' => 100.00,
            'change' => 20.00,
            'used_amount' => 100.00,
            'payment_method' => 'Cash',
            'discount' => 20.00,
            'discount_type' => 'SCPWD',
            'scpwd_id_number' => '', // Empty ID number
            'items' => [
                [
                    'medicine_id' => $medicine->medicine_id,
                    'inventory_id' => $inventory->inventory_id,
                    'quantity' => 1
                ]
            ]
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/transaction', $payload);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'success' => false,
            'message' => 'SC/PWD ID number is required for SC/PWD discounts.'
        ]);
    }
}
