<?php

namespace Tests\Feature;

use App\Models\v1\User;
use App\Models\v1\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthRateLimitingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that a user is rate-limited after 5 failed login attempts (TC-004).
     */
    public function test_login_rate_limited_after_5_failed_attempts()
    {
        $branch = Branch::factory()->create(['status' => 'active']);
        $user = User::factory()->create([
            'email' => 'testuser@example.com',
            'password' => bcrypt('password123'),
            'role' => 'pharmacist',
            'status' => 'approved',
            'branch_id' => $branch->branch_id
        ]);

        $payload = [
            'email' => 'testuser@example.com',
            'password' => 'wrongpassword'
        ];

        // 5 failed attempts
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/v1/login', $payload);
            $response->assertJsonFragment([
                'success' => false,
                'message' => 'Invalid credentials'
            ]);
        }

        // 6th attempt should be blocked
        $response = $this->postJson('/api/v1/login', $payload);
        $response->assertJsonFragment([
            'success' => false,
            'message' => 'Too many login attempts. Try again later.'
        ]);
        
        // Even with correct password, it is blocked while rate-limited
        $correctPayload = [
            'email' => 'testuser@example.com',
            'password' => 'password123'
        ];
        $response = $this->postJson('/api/v1/login', $correctPayload);
        $response->assertJsonFragment([
            'success' => false,
            'message' => 'Too many login attempts. Try again later.'
        ]);
    }
}
