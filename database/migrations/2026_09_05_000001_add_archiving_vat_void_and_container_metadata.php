<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            if (!Schema::hasColumn('medicines', 'status')) {
                $table->string('status')->default('active')->after('needs_protection')->index();
            }
        });

        Schema::table('inventories', function (Blueprint $table) {
            if (!Schema::hasColumn('inventories', 'container_type')) {
                $table->string('container_type')->nullable()->after('stocks');
            }
            if (!Schema::hasColumn('inventories', 'container_name')) {
                $table->string('container_name')->nullable()->after('container_type');
            }
            if (!Schema::hasColumn('inventories', 'container_count')) {
                $table->integer('container_count')->nullable()->after('container_name');
            }
            if (!Schema::hasColumn('inventories', 'pcs_per_container')) {
                $table->integer('pcs_per_container')->nullable()->after('container_count');
            }
        });

        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'vat_amount')) {
                $table->decimal('vat_amount', 10, 2)->default(0)->after('discount');
            }
            if (!Schema::hasColumn('transactions', 'status')) {
                $table->string('status')->default('completed')->after('used_amount')->index();
            }
            if (!Schema::hasColumn('transactions', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('status');
            }
            if (!Schema::hasColumn('transactions', 'void_reason')) {
                $table->string('void_reason')->nullable()->after('voided_at');
            }
        });

        if (Schema::hasColumn('medicines', 'status')) {
            DB::table('medicines')->whereNull('status')->update(['status' => 'active']);
        }
        if (Schema::hasColumn('transactions', 'status')) {
            DB::table('transactions')->whereNull('status')->update(['status' => 'completed']);
        }
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            foreach (['void_reason', 'voided_at', 'status', 'vat_amount'] as $column) {
                if (Schema::hasColumn('transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('inventories', function (Blueprint $table) {
            foreach (['pcs_per_container', 'container_count', 'container_name', 'container_type'] as $column) {
                if (Schema::hasColumn('inventories', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('medicines', function (Blueprint $table) {
            if (Schema::hasColumn('medicines', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
