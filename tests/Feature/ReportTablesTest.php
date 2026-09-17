<?php

namespace Tests\Feature;

use App\Http\Controllers\v1\ControlledDrugController;
use App\Http\Controllers\v1\ReportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReportTablesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $tables = [
            'branches' => 'branch_id company_id branch_name status',
            'users' => 'user_id first_name last_name deleted_at',
            'medicines' => 'medicine_id medicine_name generic_name category dosage unit type price reorder_level is_dangerous needs_protection',
            'inventories' => 'inventory_id branch_id medicine_id batch_id stocks created_at updated_at',
            'batches' => 'batch_id batch_number expiry_date mfg_date received_date location status',
            'transactions' => 'transaction_id branch_id user_id total_amount discount payment_method status reference_number transaction_type regulated_classification regulated_customer_id patient_name created_at',
            'transaction_items' => 'transaction_item_id transaction_id medicine_id batch_id batch_number expiry_date mfg_date quantity price',
            'transaction_attachments' => 'transaction_attachment_id transaction_id status',
            'regulated_customers' => 'regulated_customer_id full_name contact_number id_number formatted_address',
            'inventory_transfers' => 'inventory_transfer_id medicine_id batch_id from_branch_id to_branch_id created_at',
        ];
        foreach ($tables as $name => $fields) {
            Schema::create($name, function ($table) use ($fields) {
                foreach (explode(' ', $fields) as $field) {
                    $table->text($field)->nullable();
                }
            });
        }
        DB::table('branches')->insert(['branch_id' => 1, 'company_id' => 1, 'branch_name' => 'Main', 'status' => 'active']);
        DB::table('users')->insert(['user_id' => 1, 'first_name' => 'Test', 'last_name' => 'Dispenser']);
        DB::table('medicines')->insert(['medicine_id' => 1, 'medicine_name' => 'Regulated medicine', 'generic_name' => 'Generic', 'category' => 'Test', 'price' => 10, 'reorder_level' => 5, 'is_dangerous' => 1, 'needs_protection' => 0]);
        DB::table('batches')->insert(['batch_id' => 1, 'batch_number' => 'LOT-123', 'expiry_date' => now()->addDays(20)->toDateString(), 'mfg_date' => '2025-01-01', 'status' => 'active']);
        DB::table('inventories')->insert(['inventory_id' => 1, 'branch_id' => 1, 'medicine_id' => 1, 'batch_id' => 1, 'stocks' => 3, 'created_at' => now()->subYear()->toDateTimeString()]);
        DB::table('transactions')->insert(['transaction_id' => 1, 'branch_id' => 1, 'user_id' => 1, 'total_amount' => 20, 'discount' => 0, 'status' => 'completed', 'payment_method' => 'cash', 'regulated_classification' => 'dangerous', 'patient_name' => 'Test Patient', 'created_at' => now()->toDateTimeString()]);
        DB::table('transaction_items')->insert(['transaction_item_id' => 1, 'transaction_id' => 1, 'medicine_id' => 1, 'batch_id' => 1, 'batch_number' => 'LOT-123', 'quantity' => 2, 'price' => 10]);
    }

    public function test_controlled_inventory_and_dispensing_logbook_are_returned(): void
    {
        $data = (new ControlledDrugController)->index(Request::create('/', 'GET', ['company_id' => 1]))->getData(true)['data'];
        $this->assertSame('LOT-123', $data['inventory']['data'][0]['batch_number']);
        $this->assertSame('2025-01-01', $data['inventory']['data'][0]['mfg_date']);
        $this->assertSame(1, $data['logbook']['total']);
        $this->assertSame('Test Patient', $data['logbook']['data'][0]['customer_name']);
        $this->assertSame('Test Dispenser', $data['logbook']['data'][0]['dispenser_name']);
        $this->assertEquals(20, $data['logbook']['data'][0]['line_total']);
        DB::table('transactions')->update(['created_at' => now()->subYear()->toDateTimeString()]);
        $data = (new ControlledDrugController)->index(Request::create('/', 'GET', ['company_id' => 1]))->getData(true)['data'];
        $this->assertSame(0, $data['logbook']['total']);
        $this->assertCount(1, $data['inventory']['data']);
    }

    public function test_reports_return_sales_and_existing_stock_without_inventing_profit(): void
    {
        $data = (new ReportController)->index(Request::create('/', 'GET', ['company_id' => 1, 'days' => 7]))->getData(true)['data'];
        $this->assertCount(1, $data['tables']['recent_transactions']);
        $this->assertSame(['LOT-123'], $data['tables']['recent_transactions'][0]['batch_numbers']);
        $this->assertCount(1, $data['tables']['dangerous_transactions']);
        $this->assertCount(1, $data['tables']['inventory_watch']);
        $this->assertEquals(20, $data['summary']['total_revenue']);
        $this->assertNull($data['summary']['gross_margin_pct']);
    }

    public function test_empty_scopes_still_provide_the_complete_table_contract(): void
    {
        $request = Request::create('/', 'GET', ['company_id' => 999]);
        $controlled = (new ControlledDrugController)->index($request)->getData(true)['data'];
        $this->assertSame(['data' => [], 'total' => 0], $controlled['logbook']);
        $report = (new ReportController)->index($request)->getData(true)['data'];
        $this->assertSame([], $report['tables']['recent_transactions']);
        $this->assertArrayHasKey('gross_margin_pct', $report['summary']);
        $this->assertNull($report['summary']['gross_margin_pct']);
    }
}

