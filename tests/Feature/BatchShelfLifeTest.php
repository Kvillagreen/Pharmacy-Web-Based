<?php

namespace Tests\Feature;

use App\Models\v1\User;
use App\Models\v1\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Carbon\Carbon;

class BatchShelfLifeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test shelf life validation (TC-071) - less than 12 months rejected.
     */
    public function test_reject_batch_with_less_than_12_months_shelf_life()
    {
        $user = User::factory()->create(['role' => 'admin']);
        $branch = Branch::factory()->create(['status' => 'active']);
        
        $today = Carbon::today();
        $expiryDate = $today->copy()->addMonths(11)->format('Y-m-d'); // Less than 12 months

        $payload = [
            'medicine_name' => 'Short Shelf Life Med',
            'generic_name' => 'Generic Short',
            'pricing_type' => 'branded',
            'cost_price' => 100.00,
            'category' => 'Analgesic',
            'reorder_level' => 10,
            'stocks' => 50,
            'dosage' => 500,
            'unit' => 'mg',
            'units_per_box' => 100,
            'type' => 'Tablet',
            'is_dangerous' => false,
            'needs_protection' => false,
            'location' => 'Shelf A',
            'mfg_date' => $today->copy()->subMonths(2)->format('Y-m-d'),
            'expiry_date' => $expiryDate,
            'received_date' => $today->format('Y-m-d'),
            'branch_id' => $branch->branch_id,
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/medicine', $payload);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Stocks with less than 12 months of remaining shelf life are not accepted.'
        ]);
    }

    /**
     * Test manufacturing date cannot be in the future (TC-072).
     */
    public function test_reject_future_manufacturing_date()
    {
        $user = User::factory()->create(['role' => 'admin']);
        $branch = Branch::factory()->create(['status' => 'active']);
        
        $today = Carbon::today();
        $mfgDate = $today->copy()->addDays(2)->format('Y-m-d'); // Future mfg date

        $payload = [
            'medicine_name' => 'Future Med',
            'generic_name' => 'Generic Future',
            'pricing_type' => 'branded',
            'cost_price' => 100.00,
            'category' => 'Analgesic',
            'reorder_level' => 10,
            'stocks' => 50,
            'dosage' => 500,
            'unit' => 'mg',
            'units_per_box' => 100,
            'type' => 'Tablet',
            'is_dangerous' => false,
            'needs_protection' => false,
            'location' => 'Shelf A',
            'mfg_date' => $mfgDate,
            'expiry_date' => $today->copy()->addMonths(24)->format('Y-m-d'),
            'received_date' => $today->format('Y-m-d'),
            'branch_id' => $branch->branch_id,
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/medicine', $payload);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Manufacturing date cannot be in the future.'
        ]);
    }

    /**
     * Test manufacturing date must be before received date (TC-072).
     */
    public function test_reject_mfg_date_after_received_date()
    {
        $user = User::factory()->create(['role' => 'admin']);
        $branch = Branch::factory()->create(['status' => 'active']);
        
        $today = Carbon::today();
        $mfgDate = $today->copy()->subDays(1)->format('Y-m-d'); 
        $receivedDate = $today->copy()->subDays(2)->format('Y-m-d'); // Received before mfg

        $payload = [
            'medicine_name' => 'Future Med',
            'generic_name' => 'Generic Future',
            'pricing_type' => 'branded',
            'cost_price' => 100.00,
            'category' => 'Analgesic',
            'reorder_level' => 10,
            'stocks' => 50,
            'dosage' => 500,
            'unit' => 'mg',
            'units_per_box' => 100,
            'type' => 'Tablet',
            'is_dangerous' => false,
            'needs_protection' => false,
            'location' => 'Shelf A',
            'mfg_date' => $mfgDate,
            'expiry_date' => $today->copy()->addMonths(24)->format('Y-m-d'),
            'received_date' => $receivedDate,
            'branch_id' => $branch->branch_id,
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/medicine', $payload);

        // This would fail multiple rules depending on order, but typically we want the exact error
        // Note: received_date must be after_or_equal:today per basic validation rules first.
        // Let's adjust to pass basic validation:
        
        $mfgDate = $today->copy()->format('Y-m-d'); 
        $receivedDate = $today->copy()->format('Y-m-d'); // mfg == received will trigger 'must be before'

        $payload['mfg_date'] = $mfgDate;
        $payload['received_date'] = $receivedDate;

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/medicine', $payload);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Manufacturing date must be before the received date.'
        ]);
    }
}
