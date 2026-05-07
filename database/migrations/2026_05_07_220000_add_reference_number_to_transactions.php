<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'reference_number')) {
                $table->string('reference_number', 120)
                    ->nullable()
                    ->after('payment_method');
                $table->index(['payment_method', 'reference_number'], 'transactions_payment_reference_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'reference_number')) {
                $table->dropIndex('transactions_payment_reference_idx');
                $table->dropColumn('reference_number');
            }
        });
    }
};
