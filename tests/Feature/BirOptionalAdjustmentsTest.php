<?php

namespace Tests\Feature;

use App\Models\v1\{User, Branch, Transaction};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BirOptionalAdjustmentsTest extends TestCase
{
    use RefreshDatabase;

    private function reportUrl(array $adjustments = []): string
    {
        $user = User::factory()->create(['role' => 'owner', 'status' => 'approved']);
        $branch = Branch::findOrFail($user->branch_id);
        $branch->company->forceFill(['tax_profile'=>['entity'=>'sole_proprietor','vat_mode'=>'vat_inclusive','tax_rate_type'=>'graduated','deduction_method'=>'itemized']])->save();
        $this->actingAs($user, 'sanctum');
        $transaction=Transaction::factory()->create([
            'user_id' => $user->user_id, 'branch_id' => $branch->branch_id,
            'status' => 'completed', 'sub_total' => 1000, 'discount' => 100,
            'total_amount' => 900, 'created_at' => now()->subYear()->startOfYear(),
        ]);
        \App\Models\v1\TransactionItem::factory()->create(['transaction_id'=>$transaction->transaction_id,'quantity'=>1,'price'=>1000,'cost_price'=>0,'is_vat_exempt'=>1,'cost_includes_vat'=>0]);
        return '/api/v1/reports/bir-annual?'.http_build_query([
            'company_id' => $branch->company_id, 'branch_id' => 0, 'income_type'=>'pure_business', 'taxpayer_scope_confirmed'=>1,
            'year' => now()->year - 1, ...$adjustments,
        ]);
    }

    public function test_manual_adjustments_change_report_totals(): void
    {
        $this->getJson($this->reportUrl([
            'deductions' => 100,
            'surcharge' => 10, 'interest' => 20, 'compromise' => 30,
        ]))->assertOk()->assertJsonPath('data.taxable_net_income', 800)
            ->assertJsonPath('data.income_tax_rate', 0)
            ->assertJsonPath('data.income_tax_due', 0)
            ->assertJsonPath('data.total_amount_payable', 60);
    }

    public function test_blank_and_na_adjustments_do_not_block_generation(): void
    {
        $this->getJson($this->reportUrl([
            'deductions' => ' N/A ', 'tax_credits' => '',
            'surcharge' => '', 'interest' => 'n/a', 'compromise' => 'N/A',
        ]))->assertOk()->assertJsonPath('data.deductions', 0)
            ->assertJsonPath('data.income_tax_rate', 0)
            ->assertJsonPath('data.total_amount_payable', 0);
    }

    public function test_graduated_rate_cannot_be_overridden_and_invalid_values_are_rejected(): void
    {
        $url = $this->reportUrl(['tax_rate_percent' => 0]);
        $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors('tax_rate_percent');
        $this->getJson($url.'&deductions=-1&interest=invalid&surcharge=-5&tax_rate_percent=101')
            ->assertUnprocessable()->assertJsonValidationErrors(['deductions', 'interest', 'surcharge', 'tax_rate_percent']);
    }
}
