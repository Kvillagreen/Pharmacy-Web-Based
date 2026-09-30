<?php

uses(\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

test('dashboard all KPI branches execute and produce required summary keys', function () {
    $user = \App\Models\v1\User::factory()->create(['role' => 'admin', 'status' => 'approved']);
    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard');
    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'summary' => [
                    'total_revenue', 'transaction_count', 'average_sale',
                    'inventory_value', 'low_stock_count', 'out_of_stock_count',
                    'expiring_30_count', 'expired_count'
                ]
            ]
        ]);
});
