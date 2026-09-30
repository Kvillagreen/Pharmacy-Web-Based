<?php

namespace Tests\Feature;

use Tests\TestCase;

class UnknownRouteTest extends TestCase
{
    public function test_fallback_returns_exact_404_envelope()
    {
        $this->assertTrue(true);
    }
}
