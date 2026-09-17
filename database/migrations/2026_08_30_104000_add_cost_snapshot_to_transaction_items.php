<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_items', function (Blueprint $table) {
            $table->decimal('cost_price', 10, 2)->nullable()->after('price');
        });

        DB::table('transaction_items')
            ->join('medicines', 'medicines.medicine_id', '=', 'transaction_items.medicine_id')
            ->whereNull('transaction_items.cost_price')
            ->update(['transaction_items.cost_price' => DB::raw('medicines.cost_price')]);
    }

    public function down(): void
    {
        Schema::table('transaction_items', function (Blueprint $table) {
            $table->dropColumn('cost_price');
        });
    }
};
