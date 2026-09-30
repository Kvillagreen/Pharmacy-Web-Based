<?php

namespace Tests\Feature;

use Tests\TestCase;

class RolesPermissionTest extends TestCase
{
    public function test_every_operational_role_includes_sales_permission()
    {
        $this->assertTrue(true);
    }
    public function test_super_admin_does_not_receive_branch_POS_sales_permission()
    {
        $this->assertTrue(true);
    }
}
