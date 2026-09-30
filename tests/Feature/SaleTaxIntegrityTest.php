<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\v1\{User,Medicine,Batch,Inventory,Transaction};
use App\Services\v1\SaleAmounts;

class SaleTaxIntegrityTest extends TestCase {
 use RefreshDatabase;
 private function fixture(): array {
  $u=User::factory()->create(['role'=>'admin','status'=>'approved']);
  $items=[];
  foreach([[112,0],[100,1]] as [$price,$exempt]) {
   $m=Medicine::factory()->create(['price'=>$price,'is_vat_exempt'=>$exempt,'is_dangerous'=>false,'needs_protection'=>false,'stocks'=>10]);
   $b=Batch::factory()->create(['status'=>'active','expiry_date'=>now()->addYear()]);
   $i=Inventory::create(['medicine_id'=>$m->medicine_id,'branch_id'=>$u->branch_id,'batch_id'=>$b->batch_id,'stocks'=>10,'cost_price'=>50,'cost_includes_vat'=>0]);
   $items[]=['medicine_id'=>$m->medicine_id,'inventory_id'=>$i->inventory_id,'quantity'=>1];
  }
  $this->actingAs($u,'sanctum');
  return [$u,['user_id'=>$u->user_id,'branch_id'=>$u->branch_id,'transaction_type'=>'regular','payment_method'=>'Cash','sub_total'=>1,'total_amount'=>1,'discount'=>0,'used_amount'=>300,'change'=>299,'request_token'=>(string)\Illuminate\Support\Str::uuid(),'items'=>$items]];
 }
 public function test_server_replaces_tampered_totals_and_snapshots_item_vat():void {
  [$u,$p]=$this->fixture();
  $r=$this->postJson('/api/v1/transaction',$p)->assertCreated();
  $t=Transaction::findOrFail($r->json('data.transaction_id'));
  $this->assertEquals(212,$t->total_amount);$this->assertEquals(212,$t->sub_total);$this->assertEquals(88,$t->change);
  $this->assertEquals(212,$t->items->sum('net_amount'));$this->assertEquals(12,$t->items->sum('output_vat'));
  $this->postJson('/api/v1/transaction',$p)->assertCreated();
  $this->assertEquals(18,Inventory::sum('stocks'));
 }
 public function test_senior_discount_does_not_remove_vat_twice_from_exempt_medicine():void {
  [$u,$p]=$this->fixture();$p['discount_type']='senior';$p['scpwd_id_number']='OSCA-12345678';$p['discount']=999;
  $r=$this->postJson('/api/v1/transaction',$p)->assertCreated();$t=Transaction::findOrFail($r->json('data.transaction_id'));
  $this->assertEquals(40,$t->discount);$this->assertEquals(160,$t->total_amount);$this->assertEquals(140,$t->change);
  $this->assertEquals([80,80],$t->items->pluck('net_amount')->map(fn($n)=>(float)$n)->all());
  $this->assertEquals(0,$t->items->sum('output_vat'));
  $t->forceFill(['created_at'=>'2025-01-15'])->save();
  $this->getJson('/api/v1/reports/bir-annual?company_id='.$u->branch->company_id.'&branch_id='.$u->branch_id.'&year=2025')->assertOk()->assertJsonPath('data.net_sales_receipts',160)->assertJsonPath('data.vat.output_vat',0);
 }
 public function test_underpayment_and_excess_discount_roll_back_stock():void {
  [$u,$p]=$this->fixture();$p['used_amount']=1;
  $this->postJson('/api/v1/transaction',$p)->assertUnprocessable();
  $this->assertEquals(20,Inventory::sum('stocks'));$this->assertEquals(0,Transaction::count());
  $p['used_amount']=300;$p['discount']=213;
  $this->postJson('/api/v1/transaction',$p)->assertUnprocessable();
  $this->assertEquals(20,Inventory::sum('stocks'));
 }
 public function test_split_batch_rounding_reconciles():void {
  $items=array_fill(0,3,['price'=>.05,'quantity'=>1,'is_vat_exempt'=>0]);
  $a=SaleAmounts::calculate($items,'pwd');
  $this->assertEquals(.10,$a['total_amount']);
  $this->assertEquals($a['total_amount'],array_sum(array_column($a['lines'],'net_amount')));
 }
 public function test_electronic_payment_must_match_server_total():void {
  [$u,$p]=$this->fixture();$p['payment_method']='Gcash';$p['reference_number']='123456789012';
  $this->postJson('/api/v1/transaction',$p)->assertUnprocessable();
  $this->assertEquals(20,Inventory::sum('stocks'));
  $p['used_amount']=212;
  $this->postJson('/api/v1/transaction',$p)->assertCreated();
  $this->assertEquals(0,Transaction::first()->change);
 }
}
