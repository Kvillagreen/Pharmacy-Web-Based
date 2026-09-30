<?php

namespace Tests\Feature;

use App\Models\v1\User;
use App\Models\v1\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicinePricingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test price is auto-derived for branded medicines (10% markup).
     */
    public function test_price_is_derived_for_branded_medicine()
    {
        $user = User::factory()->create(['role' => 'admin']);
        $branch = Branch::factory()->create(['status' => 'active']);

        $payload = [
            'medicine_name' => 'Branded Med',
            'generic_name' => 'Generic Equivalent',
            'pricing_type' => 'branded',
            'cost_price' => 100.00, // Expected 10% markup -> 110.00
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
            'mfg_date' => now()->subMonths(2)->format('Y-m-d'),
            'expiry_date' => now()->addMonths(24)->format('Y-m-d'),
            'received_date' => now()->format('Y-m-d'),
            'branch_id' => $branch->branch_id,
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/medicine', $payload);

        $this->assertSame(201, $response->status(), $response->getContent());
        $response->assertJsonPath('data.medicine.pricing_type', 'branded');
        $response->assertJsonPath('data.medicine.markup_percent', '10.00'); // 10%
        $response->assertJsonPath('data.medicine.price', '110.00');
    }

    /**
     * Test price is auto-derived for generic medicines (50% markup).
     */
    public function test_price_is_derived_for_generic_medicine()
    {
        $user = User::factory()->create(['role' => 'admin']);
        $branch = Branch::factory()->create(['status' => 'active']);

        $payload = [
            'medicine_name' => 'Generic Med',
            'generic_name' => 'Generic',
            'pricing_type' => 'generic',
            'cost_price' => 100.00, // Expected 50% markup -> 150.00
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
            'mfg_date' => now()->subMonths(2)->format('Y-m-d'),
            'expiry_date' => now()->addMonths(24)->format('Y-m-d'),
            'received_date' => now()->format('Y-m-d'),
            'branch_id' => $branch->branch_id,
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/medicine', $payload);

        $this->assertSame(201, $response->status(), $response->getContent());
        $response->assertJsonPath('data.medicine.pricing_type', 'generic');
        $response->assertJsonPath('data.medicine.markup_percent', '50.00'); // 50%
        $response->assertJsonPath('data.medicine.price', '150.00');
    }

    /**
     * Test cost price validation (> 0).
     */
    public function test_cost_price_must_be_greater_than_zero()
    {
        $user = User::factory()->create(['role' => 'admin']);
        $branch = Branch::factory()->create(['status' => 'active']);

        $payload = [
            'medicine_name' => 'Free Med',
            'generic_name' => 'Free',
            'pricing_type' => 'generic',
            'cost_price' => 0, // Invalid cost price
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
            'mfg_date' => now()->subMonths(2)->format('Y-m-d'),
            'expiry_date' => now()->addMonths(24)->format('Y-m-d'),
            'received_date' => now()->format('Y-m-d'),
            'branch_id' => $branch->branch_id,
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/medicine', $payload);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Cost Price must be greater than 0.']);
    }
}
