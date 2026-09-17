<?php

namespace App\Models\v1;

use Illuminate\Database\Eloquent\Model;

class Bir2306Record extends Model
{
    protected $table = 'bir_2306_records';
    protected $primaryKey = 'bir_2306_record_id';

    protected $fillable = [
        'company_id', 'branch_id', 'period_from', 'period_to', 'payor_tin',
        'payor_registered_name', 'payor_registered_address', 'payor_zip_code',
        'nature_of_income_payment', 'atc', 'amount_of_payment', 'tax_withheld',
        'payor_signatory_name', 'payor_signatory_tin', 'payor_signatory_title',
        'certificate_date', 'source_reference', 'is_test_data',
        'payee_foreign_address', 'payee_icr_no', 'payor_tax_agent_accreditation_no',
        'payor_accreditation_date_issued', 'payor_accreditation_date_expiry', 'payor_attorney_roll_no',
        'payee_signatory_name', 'payee_signatory_tin', 'payee_signatory_title', 'payee_date_signed',
        'payee_tax_agent_accreditation_no', 'payee_accreditation_date_issued',
        'payee_accreditation_date_expiry', 'payee_attorney_roll_no', 'substituted_filing_applicable',
        'substituted_payor_signatory_name', 'substituted_payor_signatory_tin',
        'substituted_payor_signatory_title', 'substituted_payor_date_signed',
        'substituted_payee_signatory_name', 'substituted_payee_signatory_tin',
        'substituted_payee_signatory_title', 'substituted_payee_date_signed',
    ];

    protected $casts = [
        'period_from' => 'date', 'period_to' => 'date', 'certificate_date' => 'date',
        'amount_of_payment' => 'decimal:2', 'tax_withheld' => 'decimal:2',
        'is_test_data' => 'boolean',
        'payor_accreditation_date_issued' => 'date', 'payor_accreditation_date_expiry' => 'date',
        'payee_date_signed' => 'date', 'payee_accreditation_date_issued' => 'date',
        'payee_accreditation_date_expiry' => 'date', 'substituted_filing_applicable' => 'boolean',
        'substituted_payor_date_signed' => 'date', 'substituted_payee_date_signed' => 'date',
    ];
}
