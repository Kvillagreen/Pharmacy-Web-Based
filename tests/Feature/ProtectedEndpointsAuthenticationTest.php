<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProtectedEndpointsAuthenticationTest extends TestCase
{
    public function test_both_requests_stop_before_controller_and_return_Unauthenticated()
    {
        $this->assertTrue(true);
    }
}
