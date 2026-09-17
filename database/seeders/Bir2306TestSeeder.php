<?php

namespace Database\Seeders;

use App\Models\v1\Bir2306Record;
use App\Models\v1\Branch;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class Bir2306TestSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Branch::with('company')->where('status', 'active')->get() as $branch) {
            if (empty($branch->zip_code)) {
                $branch->update(['zip_code' => '4026']);
            }

            foreach ([1, 2, 3, 4] as $quarter) {
                $from = Carbon::create(2025, (($quarter - 1) * 3) + 1, 1);
                $to = $from->copy()->addMonths(3)->subDay();
                $payment = 25000 + ($branch->branch_id * 1750) + ($quarter * 1250);

                Bir2306Record::updateOrCreate(
                    ['branch_id' => $branch->branch_id, 'source_reference' => "TEST-2306-2025-Q{$quarter}"],
                    [
                        'company_id' => $branch->company_id,
                        'period_from' => $from->toDateString(),
                        'period_to' => $to->toDateString(),
                        'payor_tin' => '000-000-000-000',
                        'payor_registered_name' => 'TEST GOVERNMENT HEALTH FACILITY',
                        'payor_registered_address' => 'Test Address, Laguna',
                        'payor_zip_code' => '4026',
                        'nature_of_income_payment' => 'VAT Withholding on Purchase of Goods',
                        'atc' => 'WV010',
                        'amount_of_payment' => $payment,
                        'tax_withheld' => round($payment * 0.05, 2),
                        'payor_signatory_name' => 'TEST AUTHORIZED REPRESENTATIVE',
                        'payor_signatory_tin' => '000-000-000-000',
                        'payor_signatory_title' => 'Finance Officer (Test Data)',
                        'certificate_date' => $to->copy()->addDays(10)->toDateString(),
                        'payee_signatory_name' => 'STORE OWNER (TEST DATA)',
                        'payee_signatory_tin' => $branch->company?->tin_number,
                        'payee_signatory_title' => 'Owner / Authorized Representative',
                        'payee_date_signed' => $to->copy()->addDays(10)->toDateString(),
                        'substituted_filing_applicable' => false,
                        'is_test_data' => true,
                    ]
                );
            }
        }
    }
}
