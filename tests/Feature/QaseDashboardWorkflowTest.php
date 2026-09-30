<?php

namespace Tests\Feature;

use App\Models\v1\Batch;
use App\Models\v1\Branch;
use App\Models\v1\Company;
use App\Models\v1\Inventory;
use App\Models\v1\Medicine;
use App\Models\v1\Transaction;
use App\Models\v1\TransactionItem;
use App\Models\v1\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QaseDashboardWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    private function context(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->create(['company_id' => $company->company_id, 'branch_name' => 'Primary']);
        $other = Branch::factory()->create(['company_id' => $company->company_id, 'branch_name' => 'Secondary']);
        $user = User::factory()->create(['branch_id' => $branch->branch_id, 'role' => 'owner', 'status' => 'approved']);
        return compact('company', 'branch', 'other', 'user');
    }

    private function sale(User $user, Branch $branch, float $amount, string $method = 'Cash', ?string $date = null): Transaction
    {
        $sale = Transaction::create(['user_id'=>$user->user_id,'branch_id'=>$branch->branch_id,'transaction_type'=>'regular','total_amount'=>$amount,'payment_method'=>$method,'sub_total'=>$amount,'change'=>0,'discount'=>0,'vat_amount'=>0,'used_amount'=>$amount,'status'=>'completed']);
        if ($date) $sale->forceFill(['created_at'=>$date,'updated_at'=>$date])->saveQuietly();
        return $sale;
    }

    private function medicineWithStock(Branch $branch, int $stocks, int $reorder, string $expiry, string $category = 'Analgesic'): array
    {
        $medicine=Medicine::factory()->create(['category'=>$category,'price'=>10,'stocks'=>$stocks,'reorder_level'=>$reorder,'status'=>'active']);
        $batch=Batch::factory()->create(['batch_number'=>'DASH-'.fake()->unique()->numerify('#####'),'expiry_date'=>$expiry,'mfg_date'=>now()->subYear(),'received_date'=>now(),'status'=>'active']);
        Inventory::factory()->create(['branch_id'=>$branch->branch_id,'medicine_id'=>$medicine->medicine_id,'batch_id'=>$batch->batch_id,'stocks'=>$stocks]);
        return compact('medicine','batch');
    }

    private function dashboard(array $ctx, array $query = [])
    {
        Sanctum::actingAs($ctx['user'], ['dashboard']);
        return $this->getJson('/api/v1/dashboard?'.http_build_query(array_merge(['company_id'=>$ctx['company']->company_id], $query)));
    }

    public function test_tc_028_revenue_change_compares_current_and_previous_period(): void
    {
        $c=$this->context(); $this->sale($c['user'],$c['branch'],200,'Cash',now()->subDay()); $this->sale($c['user'],$c['branch'],100,'Cash',now()->subDays(31));
        $this->dashboard($c,['days'=>30])->assertOk()->assertJsonPath('data.summary.total_revenue',200)->assertJsonPath('data.summary.revenue_change_pct',100);
    }

    public function test_tc_029_date_range_filter_reshapes_widgets(): void
    {
        $c=$this->context(); $this->sale($c['user'],$c['branch'],50,'Cash',now()->subDays(20));
        $this->dashboard($c,['days'=>7])->assertJsonPath('data.summary.total_revenue',0)->assertJsonCount(7,'data.charts.daily_revenue');
        $this->dashboard($c,['days'=>30])->assertJsonPath('data.summary.total_revenue',50)->assertJsonCount(30,'data.charts.daily_revenue');
        $this->dashboard($c,['days'=>90])->assertJsonCount(90,'data.charts.daily_revenue');
    }

    public function test_tc_030_branch_filter_and_company_aggregate_are_scoped(): void
    {
        $c=$this->context(); $this->sale($c['user'],$c['branch'],40); $this->sale($c['user'],$c['other'],60);
        $this->dashboard($c,['branch_id'=>$c['branch']->branch_id])->assertJsonPath('data.summary.total_revenue',40)->assertJsonPath('data.scope.label','Primary');
        $this->dashboard($c)->assertJsonPath('data.summary.total_revenue',100)->assertJsonPath('data.scope.label','All Branches');
    }

    public function test_tc_031_daily_revenue_groups_transactions_by_day(): void
    {
        $c=$this->context(); $this->sale($c['user'],$c['branch'],20,'Cash',now()); $this->sale($c['user'],$c['branch'],30,'Cash',now());
        $rows=$this->dashboard($c,['days'=>7])->assertOk()->json('data.charts.daily_revenue');
        $today=collect($rows)->firstWhere('date',now()->toDateString()); $this->assertEquals(50.0,$today['total_revenue']); $this->assertSame(2,$today['transaction_count']);
    }

    public function test_tc_032_payment_mix_groups_revenue_by_method(): void
    {
        $c=$this->context(); $this->sale($c['user'],$c['branch'],25,'Cash'); $this->sale($c['user'],$c['branch'],75,'Gcash');
        $mix=collect($this->dashboard($c)->json('data.charts.payment_mix'))->keyBy('payment_method');
        $this->assertEquals(25.0,$mix['Cash']['total_revenue']); $this->assertEquals(75.0,$mix['Gcash']['total_revenue']);
    }

    public function test_tc_033_category_chart_limits_to_top_six(): void
    {
        $c=$this->context();
        foreach (range(1,7) as $i) { $m=$this->medicineWithStock($c['branch'],20,2,now()->addYear(),"Category $i")['medicine']; $sale=$this->sale($c['user'],$c['branch'],10); TransactionItem::create(['transaction_id'=>$sale->transaction_id,'medicine_id'=>$m->medicine_id,'quantity'=>$i,'price'=>10]); }
        $rows=$this->dashboard($c)->json('data.charts.category_mix'); $this->assertCount(6,$rows); $this->assertSame('Category 7',$rows[0]['category']);
    }

    public function test_tc_034_top_medicines_and_recent_transactions_are_populated(): void
    {
        $c=$this->context(); $m=$this->medicineWithStock($c['branch'],20,2,now()->addYear())['medicine']; $sale=$this->sale($c['user'],$c['branch'],30); TransactionItem::create(['transaction_id'=>$sale->transaction_id,'medicine_id'=>$m->medicine_id,'quantity'=>3,'price'=>10]);
        $response=$this->dashboard($c)->assertOk(); $response->assertJsonPath('data.tables.top_medicines.0.medicine_id',$m->medicine_id)->assertJsonPath('data.tables.recent_transactions.0.transaction_id',$sale->transaction_id);
    }

    public function test_tc_035_low_stock_expiry_and_expired_alert_counts_are_correct(): void
    {
        $c=$this->context(); $this->medicineWithStock($c['branch'],2,5,now()->addDays(10)); $this->medicineWithStock($c['branch'],0,5,now()->subDay());
        $this->dashboard($c)->assertJsonPath('data.summary.low_stock_count',2)->assertJsonPath('data.summary.out_of_stock_count',1)->assertJsonPath('data.summary.expiring_30_count',1)->assertJsonPath('data.summary.expired_count',1);
    }

    public function test_tc_036_dashboard_requires_dashboard_ability(): void
    {
        $c=$this->context(); $token=$c['user']->createToken('sales-only',['sales'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/dashboard')->assertForbidden();
    }
}
