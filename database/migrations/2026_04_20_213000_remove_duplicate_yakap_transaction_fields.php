<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('transactions')
            ->where(function ($query) {
                $query->whereNull('patient_name')->orWhere('patient_name', '');
            })
            ->whereNotNull('yakap_full_name')
            ->update([
                'patient_name' => DB::raw('yakap_full_name'),
            ]);

        DB::table('transactions')
            ->where(function ($query) {
                $query->whereNull('membership_id')->orWhere('membership_id', '');
            })
            ->whereNotNull('yakap_id_number')
            ->update([
                'membership_id' => DB::raw('yakap_id_number'),
            ]);

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'yakap_full_name',
                'yakap_id_number',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('yakap_full_name')->nullable()->after('coverage_type');
            $table->string('yakap_id_number')->nullable()->after('yakap_full_name');
        });

        DB::table('transactions')
            ->where('transaction_type', 'yakap')
            ->update([
                'yakap_full_name' => DB::raw('patient_name'),
                'yakap_id_number' => DB::raw('membership_id'),
            ]);
    }
};
