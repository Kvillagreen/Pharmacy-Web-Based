<?php

namespace Tests\Feature;

use App\Models\v1\Batch;
use App\Models\v1\Branch;
use App\Models\v1\Company;
use App\Models\v1\Inventory;
use App\Models\v1\Medicine;
use App\Models\v1\SmsMessage;
use App\Models\v1\SmsOrder;
use App\Models\v1\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QaseSmsOrderWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    private function context(): array
    {
        $company=Company::factory()->create(); $branch=Branch::factory()->create(['company_id'=>$company->company_id]);
        $user=User::factory()->create(['branch_id'=>$branch->branch_id,'role'=>'staff','status'=>'approved']);
        Sanctum::actingAs($user,['sms']);
        return compact('company','branch','user');
    }

    private function stocked(array $c, int $stock=10, float $price=25, ?string $expiry=null): array
    {
        $medicine=Medicine::factory()->create(['price'=>$price,'stocks'=>$stock,'status'=>'active']);
        $batch=Batch::factory()->create(['batch_number'=>'SMS-'.fake()->unique()->numerify('#####'),'expiry_date'=>$expiry ?: now()->addYear(),'received_date'=>now(),'mfg_date'=>now()->subYear(),'status'=>'active']);
        $inventory=Inventory::factory()->create(['branch_id'=>$c['branch']->branch_id,'medicine_id'=>$medicine->medicine_id,'batch_id'=>$batch->batch_id,'stocks'=>$stock]);
        return compact('medicine','batch','inventory');
    }

    private function inbound(array $c, string $body, array $extra=[])
    {
        return $this->postJson('/api/v1/sms/orders/inbound',array_merge(['customer_number'=>'09171234567','message_body'=>$body],$extra));
    }

    public function test_tc_057_valid_med_message_creates_pending_order_items_and_total(): void
    {
        $c=$this->context(); $a=$this->stocked($c,10,10); $b=$this->stocked($c,10,20);
        $response=$this->inbound($c,"MED {$a['medicine']->medicine_id} 2 {$b['medicine']->medicine_id} 1",['provider_message_id'=>'provider-57'])->assertCreated();
        $response->assertJsonPath('data.status','pending')->assertJsonPath('data.customer_number','09171234567')->assertJsonPath('data.total_price','40.00')->assertJsonCount(2,'data.items');
        $this->assertDatabaseHas('sms_order_items',['medicine_id'=>$a['medicine']->medicine_id,'quantity'=>2,'line_total'=>20]);
    }

    public function test_tc_058_malformed_sms_is_rejected_with_format_guidance(): void
    {
        $c=$this->context(); $this->inbound($c,'BUY TEN')->assertStatus(422)->assertJsonValidationErrors('message_body')->assertJsonFragment(['Use MED <medicine id> <quantity>, for example: MED 10 2 11 1.']);
        $this->assertDatabaseCount('sms_orders',0);
    }

    public function test_tc_059_unknown_medicine_and_insufficient_stock_are_rejected(): void
    {
        $c=$this->context(); $m=$this->stocked($c,2);
        $this->inbound($c,'MED 999999999 1')->assertStatus(422)->assertJsonValidationErrors('message_body');
        $this->inbound($c,"MED {$m['medicine']->medicine_id} 3")->assertStatus(422)->assertJsonFragment(["Insufficient stock for {$m['medicine']->medicine_name}; requested 3, available 2."]);
        $this->assertDatabaseCount('sms_orders',0);
    }

    public function test_tc_060_orders_endpoint_lists_pending_and_completed_sms_orders(): void
    {
        $c=$this->context(); $m=$this->stocked($c); $this->inbound($c,"MED {$m['medicine']->medicine_id} 1")->assertCreated();
        SmsOrder::first()->update(['status'=>'completed']);
        $this->getJson('/api/v1/sms/orders')->assertOk()->assertJsonPath('success',true)->assertJsonCount(1,'data.data')->assertJsonPath('data.data.0.status','completed');
    }

    public function test_tc_061_walk_in_processing_uses_fefo_and_creates_transaction(): void
    {
        $c=$this->context(); $early=$this->stocked($c,2,10,now()->addMonth()->toDateString());
        $lateBatch=Batch::factory()->create(['batch_number'=>'SMS-LATE','expiry_date'=>now()->addMonths(3),'received_date'=>now(),'mfg_date'=>now()->subYear(),'status'=>'active']);
        $late=Inventory::factory()->create(['branch_id'=>$c['branch']->branch_id,'medicine_id'=>$early['medicine']->medicine_id,'batch_id'=>$lateBatch->batch_id,'stocks'=>5]);
        $order=$this->inbound($c,"MED {$early['medicine']->medicine_id} 3")->assertCreated()->json('data.sms_order_id');
        $this->postJson("/api/v1/sms/orders/{$order}/process",['fulfillment_type'=>'walk-in','payment_method'=>'Cash','amount_tendered'=>50])->assertOk()->assertJsonPath('data.order.status','completed')->assertJsonCount(2,'data.transaction.items');
        $this->assertSame(0,$early['inventory']->fresh()->stocks); $this->assertSame(4,$late->fresh()->stocks);
    }

    public function test_tc_062_pending_order_can_be_cancelled_or_marked_invalid_only_once(): void
    {
        $c=$this->context(); $m=$this->stocked($c); $id=$this->inbound($c,"MED {$m['medicine']->medicine_id} 1")->json('data.sms_order_id');
        $this->patchJson("/api/v1/sms/orders/{$id}/status",['status'=>'invalid'])->assertOk()->assertJsonPath('data.status','invalid');
        $this->patchJson("/api/v1/sms/orders/{$id}/status",['status'=>'cancelled'])->assertStatus(422);
    }

    public function test_tc_063_outbound_reply_is_sent_and_stored(): void
    {
        $this->context(); config(['services.fortmed_sms.sender_name'=>'PHARMACY','services.fortmed_sms.from_number'=>'09170000000']);
        Http::fake(['*'=>Http::response(['success'=>true,'id'=>'provider-63'],200)]);
        $this->postJson('/api/v1/sms/messages',['to_number'=>'09171234567','message_body'=>'Your order is ready.'])->assertOk()->assertJsonPath('success',true);
        $this->assertDatabaseHas('sms_messages',['direction'=>'outbound','to_number'=>'09171234567','message_body'=>'Your order is ready.']);
    }

    public function test_tc_064_message_and_conversation_deletion_hide_stored_messages(): void
    {
        $this->context(); $message=SmsMessage::create(['direction'=>'inbound','from_number'=>'09171234567','to_number'=>'09170000000','normalized_from_number'=>'639171234567','normalized_to_number'=>'639170000000','counterparty_number'=>'639171234567','message_body'=>'hello','provider_received_at'=>now(),'is_deleted'=>false]);
        $this->deleteJson('/api/v1/sms/messages/'.$message->sms_message_id)->assertOk(); $this->assertTrue($message->fresh()->is_deleted);
        SmsMessage::create(['direction'=>'inbound','from_number'=>'09171234567','to_number'=>'09170000000','normalized_from_number'=>'639171234567','normalized_to_number'=>'639170000000','counterparty_number'=>'639171234567','message_body'=>'again','provider_received_at'=>now(),'is_deleted'=>false]);
        $this->deleteJson('/api/v1/sms/conversations/639171234567')->assertOk(); $this->assertSame(0,SmsMessage::where('counterparty_number','639171234567')->where('is_deleted',false)->count());
    }

    public function test_tc_065_sms_logs_and_diagnostics_have_standard_envelopes(): void
    {
        $this->context(); $this->getJson('/api/v1/sms/logs')->assertOk()->assertJsonStructure(['success','message','data']);
        $this->getJson('/api/v1/sms/diagnostics')->assertOk()->assertJsonStructure(['success','message','data']);
    }

    public function test_tc_066_sms_routes_require_sms_token_ability(): void
    {
        $c=$this->context(); Sanctum::actingAs($c['user'],['inventory']);
        foreach(['/api/v1/sms/orders','/api/v1/sms/logs','/api/v1/sms/diagnostics'] as $url) $this->getJson($url)->assertForbidden();
        $this->postJson('/api/v1/sms/messages',['to_number'=>'1','message_body'=>'x'])->assertForbidden();
    }
}
