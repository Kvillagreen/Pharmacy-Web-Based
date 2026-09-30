<?php
namespace Tests\Feature;
use App\Models\v1\{User,Transaction,Branch};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class BirUnsignedDetailsTest extends TestCase {
 use RefreshDatabase;
 public function test_original_branch_year_summary_saves_without_signatures_or_designations():void {
  $u=User::factory()->create(['role'=>'owner','status'=>'approved']);$this->actingAs($u,'sanctum');
  $branch=Branch::findOrFail($u->branch_id);$year=now()->year-1;
  Transaction::factory()->create(['user_id'=>$u->user_id,'branch_id'=>$u->branch_id,'status'=>'completed','total_amount'=>1000,'created_at'=>now()->subYear()->startOfYear()]);
  Transaction::factory()->create(['user_id'=>$u->user_id,'branch_id'=>$u->branch_id,'status'=>'voided','total_amount'=>500,'created_at'=>now()->subYear()->startOfYear()]);
  $payload=['company_id'=>$branch->company_id,'branch_id'=>$u->branch_id,'year'=>$year,'payor_tin'=>'123456789','payor_registered_name'=>'Payor','payor_registered_address'=>'Address','payor_zip_code'=>'6100','nature_of_income_payment'=>'VAT Withholding on Purchase of Goods','atc'=>'WV010','payor_signatory_name'=>'Payor representative','payee_signatory_name'=>'Payee representative','substituted_filing_applicable'=>true];
  $this->postJson('/api/v1/reports/bir-2306',$payload)->assertOk()->assertJsonPath('data.amount_of_payment',1000)->assertJsonPath('data.tax_withheld',50);
  $this->assertDatabaseHas('bir_2306_records',['branch_id'=>$u->branch_id,'certificate_date'=>null,'payor_signatory_tin'=>null]);
  $this->getJson('/api/v1/reports/bir-annual?'.http_build_query(['company_id'=>$branch->company_id,'branch_id'=>$u->branch_id,'year'=>$year]))->assertOk()->assertJsonPath('data.form_2306.total_income_payment',1000)->assertJsonPath('data.form_2306.total_tax_withheld',50);
 }
 public function test_new_reference_routes_are_removed():void {
  $this->getJson('/api/v1/reports/bir-reference/options')->assertNotFound();
 }
}
