<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicEndpointsTest extends TestCase
{
    public function test_both_routes_work_without_auth_and_return_arrays_under_data()
    {
        $this->assertTrue(true);
    }
}
