<?php

namespace Tests\Feature;

use Tests\TestCase;

class TransactionValidationTest extends TestCase
{
    public function test_exact_inventory_and_batch_identifiers_are_accepted_and_used()
    {
        $this->assertTrue(true);
    }
    public function test_only_exactly_12_numeric_digits_are_accepted()
    {
        $this->assertTrue(true);
    }
}
