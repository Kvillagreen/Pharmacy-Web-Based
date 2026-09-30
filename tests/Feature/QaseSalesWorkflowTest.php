<?php

namespace Tests\Feature;

use App\Models\v1\Batch;
use App\Models\v1\Branch;
use App\Models\v1\Company;
use App\Models\v1\Inventory;
use App\Models\v1\Medicine;
use App\Models\v1\Transaction;
use App\Models\v1\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QaseSalesWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    private function context(int $stock = 5, array $medicineOverrides = []): array
    {
        $company=Company::factory()->create(); $branch=Branch::factory()->create(['company_id'=>$company->company_id]);
        $user=User::factory()->create(['branch_id'=>$branch->branch_id,'status'=>'approved','role'=>'staff']);
        $medicine=Medicine::factory()->create(array_merge(['medicine_name'=>'Paracetamol Test','generic_name'=>'Paracetamol','category'=>'Analgesic','price'=>10,'stocks'=>$stock,'status'=>'active','is_dangerous'=>false,'needs_protection'=>false],$medicineOverrides));
        $batch=Batch::factory()->create(['batch_number'=>'SALE-'.fake()->unique()->numerify('#####'),'expiry_date'=>now()->addYear(),'received_date'=>now(),'mfg_date'=>now()->subYear(),'status'=>'active']);
        $inventory=Inventory::factory()->create(['branch_id'=>$branch->branch_id,'medicine_id'=>$medicine->medicine_id,'batch_id'=>$batch->batch_id,'stocks'=>$stock]);
        Sanctum::actingAs($user,['sales']);
        return compact('company','branch','user','medicine','batch','inventory');
    }

    private function payload(array $c, int $quantity = 1, array $overrides = []): array
    {
        $subtotal=10*$quantity;
        return array_merge(['user_id'=>$c['user']->user_id,'branch_id'=>$c['branch']->branch_id,'transaction_type'=>'regular','sub_total'=>$subtotal,'discount'=>0,'total_amount'=>$subtotal,'used_amount'=>$subtotal,'change'=>0,'payment_method'=>'Cash','items'=>[['medicine_id'=>$c['medicine']->medicine_id,'quantity'=>$quantity,'inventory_id'=>$c['inventory']->inventory_id,'batch_id'=>$c['batch']->batch_id]]],$overrides);
    }

    public function test_tc_037_pos_catalog_returns_branch_product_name_price_and_stock(): void
    {
        $c=$this->context(); $this->getJson('/api/v1/transaction?branch_id='.$c['branch']->branch_id)->assertOk()->assertJsonPath('data.0.medicine_name','Paracetamol Test')->assertJsonPath('data.0.price','10.00')->assertJsonPath('data.0.stocks',5);
    }

    public function test_tc_038_search_and_category_filters_narrow_catalog(): void
    {
        $c=$this->context();
        $this->getJson('/api/v1/transaction?branch_id='.$c['branch']->branch_id.'&search=Paracetamol')->assertJsonCount(1,'data');
        $this->getJson('/api/v1/transaction?branch_id='.$c['branch']->branch_id.'&category%5Beq%5D=Antibiotic')->assertJsonCount(0,'data');
        $this->getJson('/api/v1/transaction?branch_id='.$c['branch']->branch_id)->assertJsonCount(1,'data');
    }

    public function test_tc_039_checkout_persists_item_quantity_and_line_total(): void
    {
        $c=$this->context(); $response=$this->postJson('/api/v1/transaction',$this->payload($c))->assertCreated();
        $response->assertJsonPath('data.items.0.quantity',1)->assertJsonPath('data.items.0.price','10.00'); $this->assertDatabaseHas('transactions',['transaction_id'=>$response->json('data.transaction_id'),'sub_total'=>10]);
    }

    public function test_tc_040_out_of_stock_product_is_absent_and_checkout_is_blocked(): void
    {
        $c=$this->context(0); $this->getJson('/api/v1/transaction?branch_id='.$c['branch']->branch_id)->assertJsonCount(0,'data');
        $this->postJson('/api/v1/transaction',$this->payload($c))->assertStatus(422)->assertJsonPath('success',false); $this->assertDatabaseCount('transactions',0);
    }

    public function test_tc_041_checkout_quantity_cannot_exceed_available_stock(): void
    {
        $c=$this->context(5); $this->postJson('/api/v1/transaction',$this->payload($c,6))->assertStatus(422)->assertJsonFragment(['error'=>'Insufficient stock for Paracetamol Test']);
        $this->assertSame(5,$c['inventory']->fresh()->stocks); $this->assertDatabaseCount('transactions',0);
    }

    public function test_tc_042_omitted_cart_item_is_not_written_and_total_reflects_remaining_item(): void
    {
        $c=$this->context(); $other=Medicine::factory()->create(['price'=>20,'stocks'=>5,'status'=>'active']);
        $response=$this->postJson('/api/v1/transaction',$this->payload($c))->assertCreated();
        $response->assertJsonCount(1,'data.items')->assertJsonMissing(['medicine_id'=>$other->medicine_id]); $this->assertSame(10.0,(float)$response->json('data.total_amount'));
    }

    public function test_tc_044_custom_discount_cannot_exceed_subtotal_and_valid_discount_is_stored(): void
    {
        $c=$this->context(); $this->postJson('/api/v1/transaction',$this->payload($c,1,['discount'=>11,'total_amount'=>0]))->assertStatus(422)->assertJsonPath('message','Discount cannot exceed the subtotal.');
        $response=$this->postJson('/api/v1/transaction',$this->payload($c,1,['discount'=>2,'total_amount'=>8,'used_amount'=>8]))->assertCreated(); $this->assertEquals(2,$response->json('data.discount')); $this->assertEquals(8,$response->json('data.total_amount'));
    }

    public function test_tc_045_cash_change_is_validated_and_insufficient_tender_is_rejected(): void
    {
        $c=$this->context();
        $this->postJson('/api/v1/transaction',$this->payload($c,1,['used_amount'=>20,'change'=>5]))->assertStatus(422)->assertJsonPath('message','Change must equal amount received less total amount.');
        $this->postJson('/api/v1/transaction',$this->payload($c,1,['used_amount'=>9]))->assertStatus(422)->assertJsonPath('message','Amount received is insufficient.');
        $response=$this->postJson('/api/v1/transaction',$this->payload($c,1,['used_amount'=>20,'change'=>10]))->assertCreated(); $this->assertEquals(10,$response->json('data.change'));
    }

    public function test_tc_046_card_and_gcash_require_twelve_digit_reference(): void
    {
        $c=$this->context(); foreach(['Card','Gcash'] as $method) {
            $this->postJson('/api/v1/transaction',$this->payload($c,1,['payment_method'=>$method]))->assertStatus(422);
            $this->postJson('/api/v1/transaction',$this->payload($c,1,['payment_method'=>$method,'reference_number'=>'123']))->assertStatus(422)->assertJsonPath('message','Reference number must be exactly 12 digits.');
        }
        $this->postJson('/api/v1/transaction',$this->payload($c,1,['payment_method'=>'Gcash','reference_number'=>'123456789012']))->assertCreated()->assertJsonPath('data.reference_number','123456789012');
    }

    public function test_tc_047_payment_method_allowlist_is_enforced(): void
    {
        $c=$this->context(); $this->postJson('/api/v1/transaction',$this->payload($c,1,['payment_method'=>'Cheque']))->assertStatus(422)->assertJsonPath('message','Payment method must be Cash, Card, or Gcash.');
    }

    public function test_tc_050_checkout_requires_nonempty_items_array(): void
    {
        $c=$this->context(); $this->postJson('/api/v1/transaction',$this->payload($c,1,['items'=>[]]))->assertStatus(422)->assertJsonPath('message','Items are required.');
    }

    public function test_tc_051_controlled_sale_requires_and_persists_regulated_data(): void
    {
        $c=$this->context(5,['needs_protection'=>true]); $base=$this->payload($c,1,['transaction_type'=>'controlled']);
        $this->postJson('/api/v1/transaction',$base)->assertStatus(422)->assertJsonPath('success',false);
        $valid=array_merge($base,['patient_name'=>'Juan Dela Cruz','patient_age'=>45,'customer_address_line'=>'Bacolod','prescriber_name'=>'Dr Test','prescriber_prc_license_number'=>'1234567','prescribed_generic_name'=>'Controlled Generic','prescribed_dosage_strength'=>'10 mg','prescribed_dosage_form'=>'Tablet','prescribed_quantity_dispensed'=>1,'dispensing_date'=>now()->toDateString(),'pharmacist_signature'=>'RPh Test','prescription'=>UploadedFile::fake()->create('rx.pdf',100,'application/pdf')]);
        $response=$this->post('/api/v1/transaction',$valid,['Accept'=>'application/json'])->assertCreated(); $this->assertNotNull($response->json('data.regulated_customer_id')); $response->assertJsonPath('data.regulated_classification','controlled');
    }

    public function test_tc_054_scpwd_identifier_is_exactly_twelve_digits(): void
    {
        $c=$this->context(); $base=$this->payload($c,1,['discount_type'=>'SCPWD','discount'=>2,'total_amount'=>8,'used_amount'=>8]);
        $this->postJson('/api/v1/transaction',array_merge($base,['scpwd_id_number'=>'OSCA-12345678']))->assertStatus(422);
        $this->postJson('/api/v1/transaction',array_merge($base,['scpwd_id_number'=>'123456789012']))->assertCreated()->assertJsonPath('data.scpwd_id_number','123456789012');
    }

    public function test_tc_055_successful_sale_response_contains_receipt_source_data(): void
    {
        $c=$this->context(); $created=$this->postJson('/api/v1/transaction',$this->payload($c,1,['used_amount'=>20,'change'=>10]))->assertCreated();
        $this->getJson('/api/v1/transaction/'.$created->json('data.transaction_id'))->assertOk()->assertJsonStructure(['data'=>['items','branch','user','total_amount','sub_total','discount','payment_method','change','reference_number']]);
    }

    public function test_tc_056_server_validates_all_monetary_fields_and_totals(): void
    {
        $c=$this->context(); foreach(['sub_total','total_amount','change','used_amount','discount'] as $field) { $payload=$this->payload($c); unset($payload[$field]); $this->postJson('/api/v1/transaction',$payload)->assertStatus(422); }
        $this->postJson('/api/v1/transaction',$this->payload($c,1,['sub_total'=>10,'total_amount'=>99]))->assertStatus(422)->assertJsonPath('message','Total amount must equal subtotal less discount.');
    }
}
