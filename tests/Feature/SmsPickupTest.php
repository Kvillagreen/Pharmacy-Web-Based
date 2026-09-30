<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\{Str,Facades\Http};
use App\Models\v1\{User,Medicine,Batch,Inventory,Transaction,SmsOrder};
class SmsPickupTest extends TestCase {
 use RefreshDatabase;
 private function setupOrder():array {
  $u=User::factory()->create(['role'=>'staff','status'=>'approved']);
  $m=Medicine::factory()->create(['price'=>112,'is_vat_exempt'=>0,'is_dangerous'=>false,'needs_protection'=>false,'stocks'=>10]);
  $b=Batch::factory()->create(['status'=>'active','expiry_date'=>now()->addYear()]);
  $i=Inventory::create(['medicine_id'=>$m->medicine_id,'branch_id'=>$u->branch_id,'batch_id'=>$b->batch_id,'stocks'=>10,'cost_price'=>50,'cost_includes_vat'=>0]);
  $this->actingAs($u,'sanctum');
  $r=$this->postJson('/api/v1/sms/orders',['branch_id'=>$u->branch_id,'customer_number'=>'09171234567','message_body'=>'Pickup request','request_token'=>(string)Str::uuid(),'items'=>[['medicine_id'=>$m->medicine_id,'quantity'=>2]]])->assertCreated();
  $o=SmsOrder::findOrFail($r->json('data.sms_order_id'));
  $p=['user_id'=>$u->user_id,'branch_id'=>$u->branch_id,'transaction_type'=>'regular','payment_method'=>'Cash','sub_total'=>224,'total_amount'=>224,'discount'=>0,'used_amount'=>250,'change'=>26,'request_token'=>(string)Str::uuid(),'items'=>[['medicine_id'=>$m->medicine_id,'inventory_id'=>$i->inventory_id,'quantity'=>2]],'sms_order_id'=>$o->sms_order_id,'pickup_confirmed'=>1];
  return [$u,$i,$o,$p];
 }
 public function test_pending_and_confirmed_orders_do_not_reduce_stock_and_pickup_is_exactly_once():void {
  [$u,$i,$o,$p]=$this->setupOrder();
  $this->assertEquals(10,$i->fresh()->stocks);$this->assertSame(0,Transaction::count());
  $this->postJson('/api/v1/transaction',$p)->assertUnprocessable();
  $this->putJson('/api/v1/sms/orders/'.$o->sms_order_id.'/process',['action'=>'confirm'])->assertOk();
  $this->assertEquals(10,$i->fresh()->stocks);$this->assertSame(0,Transaction::count());
  $this->postJson('/api/v1/transaction',[...$p,'pickup_confirmed'=>0])->assertUnprocessable();
  $this->postJson('/api/v1/transaction',$p)->assertCreated();
  $this->assertEquals(8,$i->fresh()->stocks);$this->assertSame('completed',$o->fresh()->status);
  $this->assertNotNull($o->fresh()->transaction_id);
  $this->postJson('/api/v1/transaction',$p)->assertCreated();
  $this->postJson('/api/v1/transaction',[...$p,'request_token'=>(string)Str::uuid()])->assertUnprocessable();
  $this->assertSame(1,Transaction::count());$this->assertEquals(8,$i->fresh()->stocks);
 }
 public function test_wrong_cart_underpayment_and_foreign_staff_cannot_complete_order():void {
  [$u,$i,$o,$p]=$this->setupOrder();
  $this->putJson('/api/v1/sms/orders/'.$o->sms_order_id.'/process',['action'=>'confirm'])->assertOk();
  $this->postJson('/api/v1/transaction',[...$p,'used_amount'=>1])->assertUnprocessable();
  $wrong=$p;$wrong['items'][0]['quantity']=1;
  $this->postJson('/api/v1/transaction',$wrong)->assertUnprocessable();
  $this->assertSame(0,Transaction::count());$this->assertEquals(10,$i->fresh()->stocks);$this->assertSame('confirmed',$o->fresh()->status);
  $foreign=User::factory()->create(['branch_id'=>\App\Models\v1\Branch::factory()->create()->branch_id,'role'=>'staff','status'=>'approved']);$this->actingAs($foreign,'sanctum');
  $this->getJson('/api/v1/sms/orders/'.$o->sms_order_id)->assertForbidden();
  $this->putJson('/api/v1/sms/orders/'.$o->sms_order_id.'/process',['action'=>'cancel'])->assertForbidden();
 }
 public function test_sms_acceptance_uses_default_sim_branded_text_and_never_creates_sale():void {
  [$u,$i,$o]=$this->setupOrder();
  config(['services.mysmsgate_sms.api_token'=>'test','services.mysmsgate_sms.slot'=>null,'services.mysmsgate_sms.from_number'=>'09170000000']);
  Http::fake(['*'=>Http::sequence()->push(['success'=>true,'sms_id'=>123,'status'=>'pending'],202)->push(['success'=>false,'message'=>'Failed'],200)]);
  $this->postJson('/api/v1/sms/messages',['branch_id'=>$u->branch_id,'to_number'=>'09171234567','message_body'=>'Your order is awaiting confirmation.'])->assertOk()->assertJsonPath('data.delivery_status','pending');
  Http::assertSent(fn($r)=>$r['to']==='+639171234567' && str_starts_with($r['message'],'Sto. Rosario Drug Store: ') && !isset($r['slot']) && !isset($r['sender_name']));
  $this->assertSame(0,Transaction::count());$this->assertEquals(10,$i->fresh()->stocks);$this->assertSame('pending',$o->fresh()->status);
  $this->postJson('/api/v1/sms/messages',['branch_id'=>$u->branch_id,'to_number'=>'09171234567','message_body'=>'Reply'])->assertStatus(502);
  $this->assertSame(0,Transaction::count());
 }
}
