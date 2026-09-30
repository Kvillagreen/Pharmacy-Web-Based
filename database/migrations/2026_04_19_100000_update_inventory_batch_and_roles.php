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

        DB::table('inventories')->where('stocks', 0)->update([
            'stocks' => DB::raw('(SELECT stocks FROM medicines WHERE medicines.medicine_id = inventories.medicine_id)'),
        ]);

        if (Schema::hasColumn('batches', 'supplier_id')) {
            Schema::table('batches', function (Blueprint $table) {
                $table->dropForeign(['supplier_id']);
            });

            if (Schema::hasIndex('batches', 'batches_supplier_idx')) {
                Schema::table('batches', fn (Blueprint $table) => $table->dropIndex('batches_supplier_idx'));
            }

            Schema::table('batches', function (Blueprint $table) {
                $table->dropColumn('supplier_id');
            });
        }

        if (Schema::hasTable('suppliers')) {
            Schema::dropIfExists('suppliers');
        }

        Schema::table('users', fn (Blueprint $table) => $table->string('role')->change());

        DB::table('users')->where('role', 'inventory')->update(['role' => 'staff']);
        DB::table('users')->where('role', 'user')->update(['role' => 'staff']);
        DB::table('users')->where('role', 'manager')->update(['role' => 'branch_manager']);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') DB::statement("ALTER TABLE users MODIFY role ENUM('super_admin', 'admin', 'pharmacist', 'inventory', 'user', 'manager') NOT NULL");

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
