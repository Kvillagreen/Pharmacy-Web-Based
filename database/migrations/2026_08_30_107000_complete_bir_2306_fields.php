<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bir_2306_records', function (Blueprint $table) {
            $table->string('payee_foreign_address')->nullable();
            $table->string('payee_icr_no', 50)->nullable();
            $table->string('payor_tax_agent_accreditation_no')->nullable();
            $table->date('payor_accreditation_date_issued')->nullable();
            $table->date('payor_accreditation_date_expiry')->nullable();
            $table->string('payor_attorney_roll_no')->nullable();
            $table->string('payee_signatory_name')->nullable();
            $table->string('payee_signatory_tin', 30)->nullable();
            $table->string('payee_signatory_title')->nullable();
            $table->date('payee_date_signed')->nullable();
            $table->string('payee_tax_agent_accreditation_no')->nullable();
            $table->date('payee_accreditation_date_issued')->nullable();
            $table->date('payee_accreditation_date_expiry')->nullable();
            $table->string('payee_attorney_roll_no')->nullable();
            $table->boolean('substituted_filing_applicable')->default(false);
            $table->string('substituted_payor_signatory_name')->nullable();
            $table->string('substituted_payor_signatory_tin', 30)->nullable();
            $table->string('substituted_payor_signatory_title')->nullable();
            $table->date('substituted_payor_date_signed')->nullable();
            $table->string('substituted_payee_signatory_name')->nullable();
            $table->string('substituted_payee_signatory_tin', 30)->nullable();
            $table->string('substituted_payee_signatory_title')->nullable();
            $table->date('substituted_payee_date_signed')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bir_2306_records', function (Blueprint $table) {
            $table->dropColumn([
                'payee_foreign_address', 'payee_icr_no', 'payor_tax_agent_accreditation_no',
                'payor_accreditation_date_issued', 'payor_accreditation_date_expiry', 'payor_attorney_roll_no',
                'payee_signatory_name', 'payee_signatory_tin', 'payee_signatory_title', 'payee_date_signed',
                'payee_tax_agent_accreditation_no', 'payee_accreditation_date_issued',
                'payee_accreditation_date_expiry', 'payee_attorney_roll_no', 'substituted_filing_applicable',
                'substituted_payor_signatory_name', 'substituted_payor_signatory_tin',
                'substituted_payor_signatory_title', 'substituted_payor_date_signed',
                'substituted_payee_signatory_name', 'substituted_payee_signatory_tin',
                'substituted_payee_signatory_title', 'substituted_payee_date_signed',
            ]);
        });
    }
};
