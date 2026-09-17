<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            if (!Schema::hasColumn('inventories', 'stocks')) {
                $table->integer('stocks')->default(0)->after('batch_id');
            }
        });

        DB::statement('UPDATE inventories INNER JOIN medicines ON medicines.medicine_id = inventories.medicine_id SET inventories.stocks = medicines.stocks WHERE inventories.stocks = 0');

        if (Schema::hasColumn('batches', 'supplier_id')) {
            Schema::table('batches', function (Blueprint $table) {
                $table->dropForeign(['supplier_id']);
            });

            Schema::table('batches', function (Blueprint $table) {
                $table->dropColumn('supplier_id');
            });
        }

        if (Schema::hasTable('suppliers')) {
            Schema::dropIfExists('suppliers');
        }

        DB::statement("ALTER TABLE users MODIFY role ENUM('staff', 'pharmacist', 'owner', 'branch_manager', 'admin', 'super_admin') NOT NULL");

        DB::table('users')->where('role', 'inventory')->update(['role' => 'staff']);
        DB::table('users')->where('role', 'user')->update(['role' => 'staff']);
        DB::table('users')->where('role', 'manager')->update(['role' => 'branch_manager']);
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users MODIFY role ENUM('super_admin', 'admin', 'pharmacist', 'inventory', 'user', 'manager') NOT NULL");

        DB::table('users')->where('role', 'staff')->update(['role' => 'inventory']);
        DB::table('users')->where('role', 'branch_manager')->update(['role' => 'manager']);

        Schema::create('suppliers', function (Blueprint $table) {
            $table->bigIncrements('supplier_id');
            $table->string('supplier_name')->nullable();
            $table->string('supplier_first_name')->nullable();
            $table->string('supplier_last_name')->nullable();
            $table->string('contact_number')->nullable();
            $table->string('address')->nullable();
            $table->timestamps();
        });

        Schema::table('batches', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_id')->nullable()->after('batch_id');
        });

        Schema::table('batches', function (Blueprint $table) {
            $table->foreign('supplier_id')
                ->references('supplier_id')
                ->on('suppliers')
                ->nullOnDelete();
        });

        Schema::table('inventories', function (Blueprint $table) {
            if (Schema::hasColumn('inventories', 'stocks')) {
                $table->dropColumn('stocks');
            }
        });
    }
};
