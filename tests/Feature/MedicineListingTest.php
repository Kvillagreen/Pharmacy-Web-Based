<?php

namespace Tests\Feature;

use App\Http\Controllers\v1\MedicineController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MedicineListingTest extends TestCase
{
    public function test_inventory_and_public_catalog_return_grouped_batch_stock(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $columns = [
            'companies' => 'company_id company_name',
            'branches' => 'branch_id company_id branch_name branch_address branch_contact status',
            'medicines' => 'medicine_id medicine_name generic_name category type dosage unit price reorder_level is_dangerous needs_protection status',
            'inventories' => 'inventory_id branch_id medicine_id batch_id stocks container_type container_name container_count pcs_per_container created_at updated_at',
            'batches' => 'batch_id batch_number expiry_date received_date status mfg_date location created_at',
        ];
        foreach ($columns as $name => $fields) {
            Schema::create($name, function ($table) use ($fields) {
                foreach (explode(' ', $fields) as $field) {
                    $table->text($field)->nullable();
                }
            });
        }
        DB::table('companies')->insert(['company_id' => 1, 'company_name' => 'Test Pharmacy']);
        DB::table('branches')->insert(['branch_id' => 1, 'company_id' => 1, 'branch_name' => 'Main', 'status' => 'active']);
        DB::table('medicines')->insert(['medicine_id' => 1, 'medicine_name' => 'Test Medicine', 'generic_name' => 'Generic', 'category' => 'Test', 'type' => 'Tablet', 'dosage' => 5, 'unit' => 'mg', 'price' => 10, 'reorder_level' => 2, 'status' => 'active']);
        foreach ([1, 2] as $batch) {
            DB::table('batches')->insert(['batch_id' => $batch, 'batch_number' => 'B'.$batch, 'expiry_date' => now()->addYear()->toDateString(), 'received_date' => now()->toDateString(), 'status' => 'active']);
            DB::table('inventories')->insert(['inventory_id' => $batch, 'branch_id' => 1, 'medicine_id' => 1, 'batch_id' => $batch, 'stocks' => 5]);
        }
        $controller = new MedicineController;
        foreach (['index', 'publicCatalog'] as $method) {
            $response = $controller->$method(Request::create('/', 'GET', ['company_id' => 1, 'branch_id' => 1, 'group_display' => 1]));
            $this->assertSame(200, $response->status());
            $data = $response->getData(true);
            $this->assertCount(1, $data['data']);
            $this->assertSame(10, $data['data'][0]['stocks']);
        }
    }
}
