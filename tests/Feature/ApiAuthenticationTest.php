<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    public function test_api_returns_unauthorized_without_a_json_accept_header(): void
    {
        $this->get('/api/v1/dashboard')
            ->assertUnauthorized()
            ->assertJson(['success' => false, 'message' => 'Unauthenticated.']);
    }

    public function test_api_returns_unauthorized_with_a_json_accept_header(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }
}
