<?php

namespace Tests\Unit;

use App\Http\Controllers\v1\AuthController;
use App\Http\Controllers\v1\UserController;
use App\Http\Requests\v1\MethodMedicineRequest;
use App\Http\Requests\v1\MethodTransactionRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class SystemRevisionRulesTest extends TestCase
{
    public static function posRoles(): array
    {
        return [
            ['staff'],
            ['pharmacist'],
            ['branch_manager'],
            ['owner'],
            ['admin'],
        ];
    }

    #[DataProvider('posRoles')]
    public function test_operational_roles_receive_sales_pos_permission(string $role): void
    {
        foreach ([new AuthController(), new UserController()] as $controller) {
            $methodName = $controller instanceof AuthController
                ? 'rolePermissionNames'
                : 'defaultRolePermissionNames';
            $method = new ReflectionMethod($controller, $methodName);

            $this->assertContains('sales', $method->invoke($controller, $role));
        }
    }

    public function test_super_admin_is_kept_out_of_branch_pos_permissions(): void
    {
        $controller = new AuthController();
        $method = new ReflectionMethod($controller, 'rolePermissionNames');

        $this->assertNotContains('sales', $method->invoke($controller, 'super_admin'));
    }

    public function test_transaction_items_support_exact_inventory_and_batch_selection(): void
    {
        $rules = (new MethodTransactionRequest())->rules();

        $this->assertArrayHasKey('items.*.inventory_id', $rules);
        $this->assertArrayHasKey('items.*.batch_id', $rules);
        $this->assertContains('exists:inventories,inventory_id', $rules['items.*.inventory_id']);
        $this->assertContains('exists:batches,batch_id', $rules['items.*.batch_id']);
    }

    public function test_s2_license_is_limited_to_exactly_twelve_digits(): void
    {
        $rules = (new MethodTransactionRequest())->rules()['prescriber_s2_license_number'];

        $this->assertContains('size:12', $rules);
        $this->assertContains('regex:/^\d{12}$/', $rules);
    }

    public function test_inventory_accepts_generated_batches_and_requires_packaging(): void
    {
        $rules = (new MethodMedicineRequest())->rules();

        $this->assertContains('nullable', $rules['batch_number']);
        $this->assertContains('required', $rules['units_per_box']);
        $this->assertContains('min:1', $rules['units_per_box']);
    }

    public function test_inventory_requires_real_world_pricing_fields(): void
    {
        $rules = (new MethodMedicineRequest())->rules();

        $this->assertContains('required', $rules['pricing_type']);
        $this->assertContains('in:branded,generic', $rules['pricing_type']);
        $this->assertContains('required', $rules['cost_price']);
        $this->assertContains('in:10,50', $rules['markup_percent']);
    }
}
