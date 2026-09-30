<?php

namespace Tests\Feature;

use App\Services\v1\BatchTrackingReport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BatchTrackingReportTest extends TestCase
{
    public function test_batch_report_scopes_dates_and_branches_and_keeps_terminal_statuses(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::statement('CREATE TABLE inventories (inventory_id INTEGER, batch_id INTEGER, medicine_id INTEGER, branch_id INTEGER, stocks INTEGER)');
        DB::statement('CREATE TABLE batches (batch_id INTEGER, supplier TEXT, batch_number TEXT, received_date TEXT, mfg_date TEXT, expiry_date TEXT, location TEXT, status TEXT)');
        DB::statement('CREATE TABLE medicines (medicine_id INTEGER, medicine_name TEXT, generic_name TEXT)');
        DB::statement('CREATE TABLE branches (branch_id INTEGER, branch_name TEXT)');
        DB::table('branches')->insert([['branch_id' => 1, 'branch_name' => 'Main'], ['branch_id' => 2, 'branch_name' => 'Other']]);
        DB::table('medicines')->insert(['medicine_id' => 1, 'medicine_name' => 'Medicine', 'generic_name' => 'Generic']);
        foreach ([1 => 'active', 2 => 'disposed', 3 => 'pulled_out', 4 => 'active', 5 => 'active'] as $id => $status) {
            DB::table('batches')->insert([
                'batch_id' => $id, 'batch_number' => 'B'.$id, 'received_date' => $id === 5 ? '2025-12-31' : '2026-01-01',
                'expiry_date' => '2026-01-02', 'location' => 'Shelf A', 'status' => $status,
            ]);
            DB::table('inventories')->insert(['inventory_id' => $id, 'batch_id' => $id, 'medicine_id' => 1, 'branch_id' => $id === 4 ? 2 : 1, 'stocks' => $id === 2 ? 0 : 5]);
        }
        $this->travelTo(Carbon::parse('2026-01-10'));
        $report = new BatchTrackingReport;
        $rows = $report->rows([1], Carbon::parse('2026-01-01'), Carbon::parse('2026-01-01'));
        $this->assertSame([1, 2, 3], $rows->pluck('batch_id')->all());
        $this->assertSame(['expired', 'disposed', 'pulled_out'], $rows->pluck('status')->all());
        $this->assertSame([5, 0, 5], $rows->pluck('stocks')->all());
        $this->assertSame('Shelf A', $rows[0]->location);
        $this->assertCount(0, $report->rows([], Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31')));
        $this->travelBack();
    }

    public function test_stock_transfer_routes_are_not_registered(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString('inventory-transfer', $route->uri());
        }
    }
}
