<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('transactions', 'transaction_type_id')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropForeign(['transaction_type_id']);
                $table->dropColumn('transaction_type_id');
            });
        }

        if (Schema::hasTable('transaction_types')) {
            Schema::dropIfExists('transaction_types');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('transaction_types')) {
            Schema::create('transaction_types', function (Blueprint $table) {
                $table->bigIncrements('transaction_type_id');
                $table->string('transaction_type_name');
                $table->string('customer_full_name')->nullable();
                $table->string('customer_id_number')->nullable();
                $table->string('coverage_type')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasColumn('transactions', 'transaction_type_id')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->unsignedBigInteger('transaction_type_id')->nullable();

                $table->foreign('transaction_type_id')
                    ->references('transaction_type_id')
                    ->on('transaction_types')
                    ->nullOnDelete();
            });
        }
    }
};
