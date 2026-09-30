<?php

namespace Tests\Unit;

use App\Http\Requests\v1\LoginRequest;
use App\Http\Requests\v1\MethodBranchRequest;
use App\Http\Requests\v1\MethodCompanyRequest;
use App\Http\Requests\v1\MethodMedicineRequest;
use App\Http\Requests\v1\MethodTransactionRequest;
use App\Http\Requests\v1\RegisterRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RequestValidationCoverageTest extends TestCase
{
    public static function requiredFields(): array
    {
        return [
            [LoginRequest::class, ['email', 'password']],
            [RegisterRequest::class, ['firstName', 'lastName', 'email', 'password', 'branchId', 'role', 'address']],
            [MethodCompanyRequest::class, ['company_name', 'company_email']],
            [MethodBranchRequest::class, ['branch_name', 'branch_address']],
            [MethodMedicineRequest::class, ['medicine_name', 'category', 'type', 'cost_price', 'pricing_type', 'units_per_box']],
            [MethodTransactionRequest::class, ['user_id', 'branch_id', 'transaction_type', 'items', 'payment_method', 'total_amount']],
        ];
    }

    #[DataProvider('requiredFields')]
    public function test_core_requests_define_validation_for_business_fields(
        string $requestClass,
        array $fields
    ): void {
        $rules = (new $requestClass())->rules();

        foreach ($fields as $field) {
            $this->assertArrayHasKey($field, $rules, "$requestClass must validate $field");
        }
    }
}
