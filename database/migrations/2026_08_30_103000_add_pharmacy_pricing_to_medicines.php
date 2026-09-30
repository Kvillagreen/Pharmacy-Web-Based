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
            $table->string('pricing_type', 20)->default('branded')->after('category');
            $table->decimal('cost_price', 10, 2)->nullable()->after('pricing_type');
            $table->decimal('markup_percent', 5, 2)->default(10)->after('cost_price');
        });

        DB::table('medicines')->orderBy('medicine_id')->each(function ($medicine) {
            $isGeneric = mb_strtolower(trim((string) $medicine->medicine_name))
                === mb_strtolower(trim((string) $medicine->generic_name));
            $markup = $isGeneric ? 50 : 10;

            DB::table('medicines')->where('medicine_id', $medicine->medicine_id)->update([
                'pricing_type' => $isGeneric ? 'generic' : 'branded',
                'markup_percent' => $markup,
                'cost_price' => round(((float) $medicine->price) / (1 + ($markup / 100)), 2),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->dropColumn(['pricing_type', 'cost_price', 'markup_percent']);
        });
    }
};
