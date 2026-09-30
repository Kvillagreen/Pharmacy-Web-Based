<?php

namespace Tests\Unit;

use App\Services\v1\MedicineQuery;
use App\Services\v1\UserQuery;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class QueryServicesTest extends TestCase
{
    public function test_medicine_filters_only_accept_whitelisted_fields_and_operators(): void
    {
        $request = Request::create('/medicine', 'GET', [
            'medicine_name' => ['eq' => 'Paracetamol'],
            'price' => ['gt' => '10', 'bad' => 'ignored'],
            'unsafe_column' => ['eq' => 'attack'],
        ]);

        $this->assertSame([
            ['medicine_name', '=', 'Paracetamol'],
            ['price', '>', '10'],
        ], (new MedicineQuery())->transform($request));
    }

    public function test_medicine_sorting_accepts_only_whitelisted_columns(): void
    {
        $service = new MedicineQuery();

        $this->assertSame(['batches.expiry_date', 'desc'], $service->getSort(
            Request::create('/medicine', 'GET', ['sort' => '-expiry_date'])
        ));
        $this->assertNull($service->getSort(
            Request::create('/medicine', 'GET', ['sort' => 'password'])
        ));
    }

    public function test_user_filters_and_sorting_reject_unknown_fields(): void
    {
        $service = new UserQuery();
        $request = Request::create('/user', 'GET', [
            'role' => ['eq' => 'pharmacist'],
            'status' => ['eq' => 'approved'],
            'password' => ['eq' => 'secret'],
        ]);

        $this->assertSame([
            ['status', '=', 'approved'],
            ['role', '=', 'pharmacist'],
        ], $service->transform($request));
        $this->assertNull($service->getSort(
            Request::create('/user', 'GET', ['sort' => 'password'])
        ));
    }
}
