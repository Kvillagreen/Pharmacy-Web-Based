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
            $table->decimal('cost_price', 10, 2)->nullable()->after('stocks');
        });

        DB::table('inventories')
            ->join('medicines', 'medicines.medicine_id', '=', 'inventories.medicine_id')
            ->whereNull('inventories.cost_price')
            ->update(['inventories.cost_price' => DB::raw('medicines.cost_price')]);
    }

    public function down(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->dropColumn('cost_price');
        });
    }
};
