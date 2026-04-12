<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->index(['company_id', 'status'], 'branches_company_status_idx');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['branch_id', 'created_at'], 'transactions_branch_created_idx');
            $table->index(['branch_id', 'created_at', 'payment_method'], 'transactions_branch_created_payment_idx');
            $table->index(['user_id', 'created_at'], 'transactions_user_created_idx');
        });

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->index(['transaction_id', 'medicine_id'], 'transaction_items_transaction_medicine_idx');
            $table->index(['medicine_id', 'transaction_id'], 'transaction_items_medicine_transaction_idx');
        });

        Schema::table('inventories', function (Blueprint $table) {
            $table->index(['branch_id', 'medicine_id'], 'inventories_branch_medicine_idx');
            $table->index(['medicine_id', 'branch_id'], 'inventories_medicine_branch_idx');
            $table->index(['batch_id', 'branch_id'], 'inventories_batch_branch_idx');
        });

        Schema::table('batches', function (Blueprint $table) {
            $table->index(['expiry_date', 'status'], 'batches_expiry_status_idx');
            $table->index('supplier_id', 'batches_supplier_idx');
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropIndex('batches_supplier_idx');
            $table->dropIndex('batches_expiry_status_idx');
        });

        Schema::table('inventories', function (Blueprint $table) {
            $table->dropIndex('inventories_batch_branch_idx');
            $table->dropIndex('inventories_medicine_branch_idx');
            $table->dropIndex('inventories_branch_medicine_idx');
        });

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->dropIndex('transaction_items_medicine_transaction_idx');
            $table->dropIndex('transaction_items_transaction_medicine_idx');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_user_created_idx');
            $table->dropIndex('transactions_branch_created_payment_idx');
            $table->dropIndex('transactions_branch_created_idx');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropIndex('branches_company_status_idx');
        });
    }
};
