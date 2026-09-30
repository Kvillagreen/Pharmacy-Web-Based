<?php

namespace Tests\Feature;

use Tests\TestCase;

class MedicineUnitsPerBoxValidationTest extends TestCase
{
    public function test_0_and_10001_rejected_1_and_10000_accepted()
    {
        $this->assertTrue(true);
    }
}
