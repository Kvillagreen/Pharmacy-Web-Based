<?php
namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\v1\{User, Inventory, Medicine, Branch};

class LowStockAndQuarterlyTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_role_receives_low_stock_even_when_optional_alerts_are_disabled(): void
    {
        $branch = Branch::factory()->create();
        $medicine = Medicine::factory()->create(['reorder_level'=>5]);
        $inventory = Inventory::factory()->create(['branch_id'=>$branch->branch_id,'medicine_id'=>$medicine->medicine_id,'stocks'=>5]);
        foreach (['staff','pharmacist','branch_manager','owner','admin'] as $role) {
            $user = User::factory()->create(['branch_id'=>$branch->branch_id,'role'=>$role,'status'=>'approved','notify_low_stock'=>false]);
            $response = $this->actingAs($user,'sanctum')->getJson('/api/v1/header/notifications?branch_id='.$branch->branch_id)->assertOk();
            $alerts = collect($response->json('data.notifications'))->where('type','inventory');
            $this->assertCount(1,$alerts);
            $this->assertStringContainsString('5 stock level',$alerts->first()['message']);
        }
        $inventory->update(['stocks'=>6]);
        $response=$this->getJson('/api/v1/header/notifications?branch_id='.$branch->branch_id)->assertOk();
        $this->assertCount(0,collect($response->json('data.notifications'))->where('type','inventory'));
    }

    public function test_stock_alerts_preserve_branch_access(): void
    {
        $user=User::factory()->create(['role'=>'staff','status'=>'approved']);
        $other=Branch::factory()->create();
        $this->actingAs($user,'sanctum')->getJson('/api/v1/header/notifications?branch_id='.$other->branch_id)->assertForbidden();
    }

    public function test_quarterly_modal_contract_returns_numbered_template_rows(): void
    {
        $user=User::factory()->create(['role'=>'owner','status'=>'approved']);
        $this->actingAs($user,'sanctum')->getJson('/api/v1/reports/bir-annual?'.http_build_query([
            'company_id'=>$user->branch->company_id,'branch_id'=>$user->branch_id,'year'=>2025,'quarter'=>2,
            'tax_method'=>'graduated_itemized','income_type'=>'business','previous_income'=>400000,'current_withholding'=>1000
        ]))->assertOk()->assertJsonPath('data.period_from','2025-04-01')->assertJsonPath('data.period_to','2025-06-30')
            ->assertJsonPath('data.income_tax_due',22500)->assertJsonPath('data.total_amount_payable',21500)
            ->assertJsonPath('data.computation_rows.0.item','36');
    }
}
