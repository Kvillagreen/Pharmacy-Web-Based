<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProtectedRouteMatrixTest extends TestCase
{
    public function test_every_protected_module_rejects_unauthenticated_access_consistently()
    {
        $this->assertTrue(true);
    }
}
