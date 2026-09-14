<?php

namespace Tests\Feature;

use App\Http\Controllers\v1\TransactionController;
use App\Models\v1\Branch;
use App\Models\v1\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TransactionVoidTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::statement('CREATE TABLE branches (branch_id INTEGER PRIMARY KEY, company_id INTEGER)');
        DB::statement('CREATE TABLE transactions (transaction_id INTEGER PRIMARY KEY, branch_id INTEGER, status TEXT, voided_at TEXT, void_reason TEXT, updated_at TEXT)');
        DB::statement('CREATE TABLE transaction_items (transaction_item_id INTEGER PRIMARY KEY, transaction_id INTEGER, medicine_id INTEGER, batch_id INTEGER, quantity INTEGER)');
        DB::statement('CREATE TABLE inventories (inventory_id INTEGER PRIMARY KEY, branch_id INTEGER, medicine_id INTEGER, batch_id INTEGER, stocks INTEGER, updated_at TEXT)');
        DB::statement('CREATE TABLE medicines (medicine_id INTEGER PRIMARY KEY, stocks INTEGER, updated_at TEXT)');
        DB::table('branches')->insert(['branch_id' => 1, 'company_id' => 1]);
        DB::table('transactions')->insert(['transaction_id' => 1, 'branch_id' => 1, 'status' => 'completed']);
        DB::table('medicines')->insert(['medicine_id' => 1, 'stocks' => 15]);
        foreach ([1 => 3, 2 => 2] as $batch => $quantity) {
            DB::table('transaction_items')->insert(['transaction_item_id' => $batch, 'transaction_id' => 1, 'medicine_id' => 1, 'batch_id' => $batch, 'quantity' => $quantity]);
            DB::table('inventories')->insert(['inventory_id' => $batch, 'branch_id' => 1, 'medicine_id' => 1, 'batch_id' => $batch, 'stocks' => 5]);
        }
        DB::table('inventories')->insert(['inventory_id' => 3, 'branch_id' => 2, 'medicine_id' => 1, 'batch_id' => 1, 'stocks' => 5]);
    }

    private function voidSale(int $company = 1)
    {
        $user = (new User)->forceFill(['branch_id' => 1, 'role' => 'admin']);
        $user->setRelation('branch', (new Branch)->forceFill(['company_id' => $company]));
        $request = Request::create('/transaction/1/void', 'POST');
        $request->setUserResolver(fn () => $user);
        return (new TransactionController)->void($request, '1');
    }

    public function test_void_restores_each_original_batch_once_and_keeps_other_branches_unchanged(): void
    {
        $this->assertSame(200, $this->voidSale()->status());
        $this->assertSame([8, 7, 5], DB::table('inventories')->orderBy('inventory_id')->pluck('stocks')->all());
        $this->assertSame(20, DB::table('medicines')->value('stocks'));
        $this->assertSame('voided', DB::table('transactions')->value('status'));
        $this->assertSame(422, $this->voidSale()->status());
        $this->assertSame(20, DB::table('inventories')->sum('stocks'));
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_missing_batch_rolls_back_every_stock_change(): void
    {
        DB::table('inventories')->where('inventory_id', 2)->delete();
        $this->assertSame(422, $this->voidSale()->status());
        $this->assertSame(5, DB::table('inventories')->where('inventory_id', 1)->value('stocks'));
        $this->assertSame('completed', DB::table('transactions')->value('status'));
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_other_company_cannot_void_sale(): void
    {
        $this->assertSame(403, $this->voidSale(2)->status());
        $this->assertSame(15, DB::table('inventories')->sum('stocks'));
        $this->assertSame('completed', DB::table('transactions')->value('status'));
    }
}
