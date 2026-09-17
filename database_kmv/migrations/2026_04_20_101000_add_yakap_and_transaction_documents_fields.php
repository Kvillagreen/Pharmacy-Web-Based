<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->boolean('is_yakap_eligible')->default(false)->after('is_dangerous');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('transaction_type')->default('regular')->after('branch_id');
            $table->string('hmo_provider')->nullable()->after('transaction_type');
            $table->string('patient_name')->nullable()->after('hmo_provider');
            $table->string('membership_id')->nullable()->after('patient_name');
            $table->string('coverage_type')->nullable()->after('membership_id');
            $table->string('yakap_full_name')->nullable()->after('coverage_type');
            $table->string('yakap_id_number')->nullable()->after('yakap_full_name');
            $table->string('prescription_path')->nullable()->after('yakap_id_number');
            $table->string('member_id_image_path')->nullable()->after('prescription_path');
            $table->boolean('documents_submitted')->default(false)->after('member_id_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'transaction_type',
                'hmo_provider',
                'patient_name',
                'membership_id',
                'coverage_type',
                'yakap_full_name',
                'yakap_id_number',
                'prescription_path',
                'member_id_image_path',
                'documents_submitted',
            ]);
        });

        Schema::table('medicines', function (Blueprint $table) {
            $table->dropColumn('is_yakap_eligible');
        });
    }
};
