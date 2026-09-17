<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('zip_code', 10)->nullable()->after('branch_contact');
        });

        Schema::create('bir_2306_records', function (Blueprint $table) {
            $table->id('bir_2306_record_id');
            $table->foreignId('company_id')->constrained('companies', 'company_id')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches', 'branch_id')->cascadeOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->string('payor_tin', 30);
            $table->string('payor_registered_name');
            $table->string('payor_registered_address');
            $table->string('payor_zip_code', 10);
            $table->string('nature_of_income_payment');
            $table->string('atc', 20);
            $table->decimal('amount_of_payment', 14, 2);
            $table->decimal('tax_withheld', 14, 2);
            $table->string('payor_signatory_name')->nullable();
            $table->string('payor_signatory_tin', 30)->nullable();
            $table->string('payor_signatory_title')->nullable();
            $table->date('certificate_date')->nullable();
            $table->string('source_reference')->nullable();
            $table->boolean('is_test_data')->default(false);
            $table->timestamps();
            $table->unique(['branch_id', 'source_reference']);
            $table->index(['branch_id', 'period_from', 'period_to'], 'bir2306_branch_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bir_2306_records');
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('zip_code'));
    }
};
