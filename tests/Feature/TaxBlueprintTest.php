<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\v1\{User,Branch,Transaction,TransactionItem,Medicine};
use App\Services\v1\BranchTaxSummary;
class TaxBlueprintTest extends TestCase {
 use RefreshDatabase;
 private function sale(User $u,array $v=[]):Transaction {
  $v+=['price'=>112,'cost'=>56,'month'=>1,'exempt'=>0,'costVat'=>1,'total'=>112,'status'=>'completed'];
  $t=Transaction::factory()->create(['user_id'=>$u->user_id,'branch_id'=>$u->branch_id,'sub_total'=>$v['price'],'total_amount'=>$v['total'],'discount'=>$v['price']-$v['total'],'discount_type'=>'regular','status'=>$v['status'],'created_at'=>sprintf('2025-%02d-15 12:00:00',$v['month'])]);
  TransactionItem::factory()->create(['transaction_id'=>$t->transaction_id,'quantity'=>1,'price'=>$v['price'],'cost_price'=>$v['cost'],'is_vat_exempt'=>$v['exempt'],'cost_includes_vat'=>$v['costVat']]);return $t;
 }
 private function url(User $u,array $v=[]):string{ $u->branch->company->forceFill(['tax_profile'=>['entity'=>'sole_proprietor','vat_mode'=>$v['vat_mode']??'vat_inclusive','tax_rate_type'=>$v['tax_rate_type']??'graduated','deduction_method'=>'itemized']])->save();return '/api/v1/reports/bir-annual?'.http_build_query(['company_id'=>Branch::findOrFail($u->branch_id)->company_id,'branch_id'=>0,'year'=>2025,'income_type'=>'pure_business','taxpayer_scope_confirmed'=>1,...$v]);}
 public function test_all_brackets():void {foreach([[0,0],[250000,0],[400000,22500],[800000,102500],[1500000,277500],[2000000,402500],[8000000,2202500],[9000000,2552500]] as [$n,$tax])$this->assertSame((float)$tax,BranchTaxSummary::graduated($n)['tax_due']);}
 public function test_discount_exemption_status_and_historical_prices():void {
  $u=User::factory()->create(['role'=>'owner','status'=>'approved']);$this->actingAs($u,'sanctum');$t=$this->sale($u,['total'=>100.8]);$this->sale($u,['price'=>100,'cost'=>50,'exempt'=>1,'total'=>100]);$this->sale($u,['status'=>'voided']);$this->sale($u,['status'=>'pending']);
  $item=TransactionItem::where('transaction_id',$t->transaction_id)->first();Medicine::find($item->medicine_id)->update(['price'=>999,'is_vat_exempt'=>1]);
  $this->getJson($this->url($u))->assertOk()->assertJsonPath('data.net_sales_receipts',190)->assertJsonPath('data.vat.output_vat',10.8)->assertJsonPath('data.cost_of_sales',100)->assertJsonPath('data.vat.input_vat',6)->assertJsonPath('data.transaction_count',2);
 }
 public function test_cumulative_quarters_nonvat_and_credits():void {
  $u=User::factory()->create(['role'=>'owner','status'=>'approved']);$this->actingAs($u,'sanctum');foreach([1,4,7] as $month)$this->sale($u,['month'=>$month]);
  $this->getJson($this->url($u,['quarter'=>2,'vat_mode'=>'non_vat','tax_credits'=>25]))->assertOk()->assertJsonPath('data.net_sales_receipts',224)->assertJsonPath('data.vat.output_vat',0)->assertJsonPath('data.cost_of_sales',112)->assertJsonPath('data.total_amount_payable',-25)->assertJsonPath('data.form_no','1701Q')->assertJsonPath('data.period_to','2025-06-30');
 }
 public function test_unknown_data_marked_provisional():void {
  $u=User::factory()->create(['role'=>'owner','status'=>'approved']);$this->actingAs($u,'sanctum');$this->sale($u,['exempt'=>null,'costVat'=>null]);Transaction::factory()->create(['user_id'=>$u->user_id,'branch_id'=>$u->branch_id,'status'=>'completed','created_at'=>'2025-01-15']);
  $this->getJson($this->url($u))->assertOk()->assertJsonPath('data.is_provisional',true)->assertJsonPath('data.vat.unverified_lines',1)->assertJsonPath('data.unitemized_transactions',1);
 }
 public function test_company_aggregation_and_manager_scope():void {
  $u=User::factory()->create(['role'=>'owner','status'=>'approved']);$b=Branch::findOrFail($u->branch_id);$b2=Branch::factory()->create(['company_id'=>$b->company_id,'status'=>'active']);$other=User::factory()->create(['branch_id'=>$b2->branch_id,'role'=>'branch_manager','status'=>'approved']);$foreignBranch=Branch::factory()->create(['company_id'=>\App\Models\v1\Company::factory()->create()->company_id,'status'=>'active']);$foreign=User::factory()->create(['branch_id'=>$foreignBranch->branch_id,'role'=>'owner','status'=>'approved']);
  foreach([$u,$other,$foreign] as $who)$this->sale($who,['price'=>336000,'cost'=>0,'total'=>336000]);
  $this->actingAs($u,'sanctum');$this->getJson($this->url($u,['branch_id'=>0]))->assertOk()->assertJsonPath('data.net_sales_receipts',600000)->assertJsonPath('data.income_tax_due',62500)->assertJsonPath('data.scope_type','company');
  $this->actingAs($other,'sanctum');$this->getJson($this->url($other,['branch_id'=>0]))->assertOk()->assertJsonPath('data.net_sales_receipts',300000)->assertJsonPath('data.scope_type','branch');$this->getJson($this->url($other,['branch_id'=>$u->branch_id]))->assertForbidden();
 }
 public function test_invalid_period_and_rate_override():void {
  $u=User::factory()->create(['role'=>'owner','status'=>'approved']);$this->actingAs($u,'sanctum');foreach([['year'=>2022],['year'=>now()->year],['quarter'=>4],['tax_rate_percent'=>25]] as $v)$this->getJson($this->url($u,$v))->assertUnprocessable();
 }
 public function test_inactive_branch_history_is_included_and_branch_tax_is_not_a_separate_liability():void {
  $u=User::factory()->create(['role'=>'owner','status'=>'approved']);$this->actingAs($u,'sanctum');
  $b=Branch::factory()->create(['company_id'=>$u->branch->company_id,'status'=>'inactive']);
  $other=User::factory()->create(['branch_id'=>$b->branch_id]);$this->sale($other,['price'=>336000,'total'=>336000,'cost'=>0]);
  $this->getJson($this->url($u))->assertOk()->assertJsonPath('data.net_sales_receipts',300000)->assertJsonPath('data.income_tax_due',7500);
  $this->getJson($this->url($u,['branch_id'=>$u->branch_id]))->assertOk()->assertJsonPath('data.calculation_available',false)->assertJsonPath('data.income_tax_due',null);
 }
 public function test_missing_historical_evidence_is_not_zero_and_mixed_income_requires_compensation():void {
  $u=User::factory()->create(['role'=>'owner','status'=>'approved']);$this->actingAs($u,'sanctum');
  $t=$this->sale($u,['cost'=>null,'exempt'=>null]);
  $this->getJson($this->url($u))->assertOk()->assertJsonPath('data.cost_of_sales',null)->assertJsonPath('data.net_sales_receipts',null)->assertJsonPath('data.income_tax_due',null)->assertJsonPath('data.business_net_income',null);
  $t->items()->update(['cost_price'=>0,'is_vat_exempt'=>1]);
  $this->getJson($this->url($u,['income_type'=>'mixed_income']))->assertOk()->assertJsonPath('data.income_tax_due',null);
  $this->getJson($this->url($u,['income_type'=>'mixed_income','taxable_compensation'=>400000,'deductions'=>1000]))->assertOk()->assertJsonPath('data.taxable_net_income',400000)->assertJsonPath('data.income_tax_due',22500);
 }
 public function test_confirmed_profile_cannot_be_overridden_and_scope_requires_confirmation():void {
  $u=User::factory()->create(['role'=>'owner','status'=>'approved']);$this->actingAs($u,'sanctum');$url=$this->url($u);
  $this->getJson($url.'&vat_mode=non_vat')->assertUnprocessable()->assertJsonValidationErrors('vat_mode');
  $this->getJson($url.'&taxpayer_scope_confirmed=0')->assertOk()->assertJsonPath('data.calculation_available',false)->assertJsonPath('data.income_tax_due',null);
 }
 public function test_eight_percent_requires_eligibility_and_uses_correct_income_basis():void {
  $u=User::factory()->create(['role'=>'owner','status'=>'approved']);$this->actingAs($u,'sanctum');
  $this->sale($u,['price'=>500000,'total'=>500000,'exempt'=>1,'cost'=>100000]);
  $v=['branch_id'=>0,'tax_rate_type'=>'8_percent','vat_mode'=>'non_vat','income_type'=>'pure_business','eight_percent_eligible'=>1,'other_business_income'=>10000];
  $this->getJson($this->url($u,$v))->assertOk()->assertJsonPath('data.income_tax_due',20800)->assertJsonPath('data.taxable_net_income',260000)->assertJsonPath('data.form_no','1701A');
  $this->getJson($this->url($u,[...$v,'income_type'=>'mixed_income']))->assertOk()->assertJsonPath('data.income_tax_due',40800)->assertJsonPath('data.taxable_net_income',510000)->assertJsonPath('data.graduated_tax.floor',0);
  foreach([['eight_percent_eligible'=>0],['income_type'=>''],['other_business_income'=>''],['vat_mode'=>'vat_inclusive'],['branch_id'=>$u->branch_id],['other_business_income'=>2500001],['deductions'=>100]] as $invalid) {
   $this->getJson($this->url($u,[...$v,...$invalid]))->assertUnprocessable();
  }
 }
}
