<?php
namespace Tests\Feature;
use App\Models\v1\{Batch, Branch, Inventory, Medicine, Transaction, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TransactionVoidTest extends TestCase
{
    use RefreshDatabase;
    private User $cashier;
    private Transaction $sale;
    private Medicine $medicine;
    private array $inventories = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'admin', 'status' => 'approved', 'manager_pin_hash' => Hash::make('1234')]);
        $this->medicine = Medicine::factory()->create(['stocks' => 15, 'is_dangerous' => false, 'needs_protection' => false]);
        $this->sale = Transaction::create([
            'user_id' => $this->cashier->user_id, 'branch_id' => $this->cashier->branch_id,
            'status' => 'completed', 'total_amount' => 50, 'sub_total' => 50,
            'payment_method' => 'Cash', 'used_amount' => 50, 'change' => 0,
        ]);
        foreach ([3, 2] as $quantity) {
            $batch = Batch::factory()->create(['expiry_date' => now()->addYear()->toDateString()]);
            $this->inventories[] = Inventory::create(['branch_id' => $this->cashier->branch_id, 'medicine_id' => $this->medicine->medicine_id, 'batch_id' => $batch->batch_id, 'stocks' => 5]);
            $this->sale->items()->create(['medicine_id' => $this->medicine->medicine_id, 'batch_id' => $batch->batch_id, 'quantity' => $quantity, 'price' => 10]);
        }
        $other = Branch::factory()->create(['company_id' => $this->cashier->branch->company_id]);
        $this->inventories[] = Inventory::create(['branch_id' => $other->branch_id, 'medicine_id' => $this->medicine->medicine_id, 'batch_id' => $this->inventories[0]->batch_id, 'stocks' => 5]);
        $this->actingAs($this->cashier, 'sanctum');
    }
    private function voidSale(string $pin = '1234')
    {
        return $this->postJson('/api/v1/transaction/'.$this->sale->transaction_id.'/void', ['manager_id'=>$this->cashier->user_id,'manager_pin' => $pin, 'void_reason' => 'Test correction']);
    }
    public function test_sale_updates_stock_once_and_both_reporting_modules(): void
    {
        $this->medicine->update(['price' => 10]);
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.summary.total_revenue', 50);
        $this->getJson('/api/v1/reports')->assertOk()->assertJsonPath('data.summary.total_revenue', 50);
        $inventory = $this->inventories[0];
        $payload = [
            'user_id' => $this->cashier->user_id, 'branch_id' => $this->cashier->branch_id,
            'transaction_type' => 'regular', 'total_amount' => 20, 'sub_total' => 20,
            'used_amount' => 20, 'change' => 0, 'discount' => 0, 'payment_method' => 'Cash',
            'request_token' => (string) \Illuminate\Support\Str::uuid(),
            'items' => [['medicine_id' => $this->medicine->medicine_id, 'inventory_id' => $inventory->inventory_id, 'batch_id' => $inventory->batch_id, 'quantity' => 2]],
        ];
        $this->postJson('/api/v1/transaction', $payload)->assertCreated();
        $this->postJson('/api/v1/transaction', $payload)->assertCreated();
        $this->assertSame(3, (int) $inventory->fresh()->stocks);
        $this->assertSame(13, (int) $this->medicine->fresh()->stocks);
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.summary.total_revenue', 70);
        $this->getJson('/api/v1/reports')->assertOk()->assertJsonPath('data.summary.total_revenue', 70);
        $inventory->batch->update(['status' => 'archived']);
        $payload['request_token'] = (string) \Illuminate\Support\Str::uuid();
        $this->postJson('/api/v1/transaction', $payload)->assertStatus(422);
        $this->assertSame(3, (int) $inventory->fresh()->stocks);
    }
    public function test_void_restores_original_batches_once_and_refreshes_dashboard_and_reports(): void
    {
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.summary.total_revenue', 50);
        $this->getJson('/api/v1/reports')->assertOk()->assertJsonPath('data.summary.total_revenue', 50);
        $this->voidSale()->assertOk();
        $this->assertSame([8, 7, 5], array_map(fn ($i) => (int) $i->fresh()->stocks, $this->inventories));
        $this->assertSame(20, (int) $this->medicine->fresh()->stocks);
        $this->voidSale()->assertStatus(409);
        $this->assertSame(20, (int) $this->medicine->fresh()->stocks);
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.summary.total_revenue', 0);
        $this->getJson('/api/v1/reports')->assertOk()->assertJsonPath('data.summary.total_revenue', 0);
    }
    public function test_invalid_manager_pin_does_not_change_stock(): void
    {
        $this->voidSale('9999')->assertStatus(422);
        $this->assertSame([5, 5, 5], array_map(fn ($i) => (int) $i->fresh()->stocks, $this->inventories));
        $this->assertSame('completed', $this->sale->fresh()->status);
    }
    public function test_other_company_cannot_void_sale(): void
    {
        $branch = Branch::factory()->create();
        $other = User::factory()->create(['role' => 'admin', 'status' => 'approved', 'branch_id' => $branch->branch_id]);
        $this->actingAs($other, 'sanctum');
        $this->voidSale()->assertForbidden();
        $this->assertSame('completed', $this->sale->fresh()->status);
    }

    public function test_sale_token_is_required_and_reuse_must_match_the_payload(): void
    {
        $this->medicine->update(['price'=>10]);
        $payload=['user_id'=>$this->cashier->user_id,'branch_id'=>$this->cashier->branch_id,'transaction_type'=>'regular',
            'total_amount'=>10,'sub_total'=>10,'used_amount'=>10,'change'=>0,'discount'=>0,'payment_method'=>'Cash',
            'items'=>[['medicine_id'=>$this->medicine->medicine_id,'inventory_id'=>$this->inventories[0]->inventory_id,'quantity'=>1]]];
        $this->postJson('/api/v1/transaction',$payload)->assertUnprocessable()->assertJsonValidationErrors('request_token');
        $payload['request_token']=(string)\Illuminate\Support\Str::uuid();
        $first=$this->postJson('/api/v1/transaction',$payload)->assertCreated();
        $this->postJson('/api/v1/transaction',$payload)->assertCreated()->assertJsonPath('data.transaction_id',$first->json('data.transaction_id'));
        $payload['used_amount']=20;
        $this->postJson('/api/v1/transaction',$payload)->assertStatus(409);
        $this->assertSame(4,(int)$this->inventories[0]->fresh()->stocks);
    }

    public function test_void_requires_named_approver_and_reason():void
    {
        $this->postJson('/api/v1/transaction/'.$this->sale->transaction_id.'/void',['manager_pin'=>'1234'])->assertUnprocessable()->assertJsonValidationErrors(['manager_id','void_reason']);
        $this->getJson('/api/v1/transaction/'.$this->sale->transaction_id.'/approvers')->assertOk()->assertJsonFragment(['user_id'=>$this->cashier->user_id]);
        $this->postJson('/api/v1/transaction/'.$this->sale->transaction_id.'/void',['manager_id'=>999999,'manager_pin'=>'1234','void_reason'=>'Test'])->assertUnprocessable();
        $this->assertSame('completed',$this->sale->fresh()->status);
    }

    public function test_distinct_approver_policy_prevents_self_approval(): void
    {
        config(['operations.require_distinct_void_approver' => true]);
        $this->voidSale()->assertUnprocessable();
        $this->assertSame('completed', $this->sale->fresh()->status);
        $manager = User::factory()->create(['branch_id' => $this->cashier->branch_id, 'role' => 'admin', 'status' => 'approved', 'manager_pin_hash' => Hash::make('5678')]);
        $this->postJson('/api/v1/transaction/'.$this->sale->transaction_id.'/void', ['manager_id' => $manager->user_id, 'manager_pin' => '5678', 'void_reason' => 'Independent review'])->assertOk();
    }
}
