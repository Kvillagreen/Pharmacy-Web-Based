<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUpTraits()
    {
        if (config('database.default') === 'mysql' && !str_starts_with((string) config('database.connections.mysql.database'), 'pharmacy_test_')) {
            throw new \RuntimeException('Tests may only use a dedicated pharmacy_test_ MySQL database.');
        }
        return parent::setUpTraits();
    }
}
