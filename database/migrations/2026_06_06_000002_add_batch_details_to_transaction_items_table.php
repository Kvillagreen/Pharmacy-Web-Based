<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_items', function (Blueprint $table) {
            $table->unsignedBigInteger('batch_id')->nullable()->after('transaction_id');
            $table->string('batch_number')->nullable()->after('batch_id');
            $table->date('expiry_date')->nullable()->after('batch_number');
            $table->date('mfg_date')->nullable()->after('expiry_date');
            $table->decimal('price', 10, 2)->nullable()->after('quantity');

            $table->foreign('batch_id')
                ->references('batch_id')
                ->on('batches')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('transaction_items', function (Blueprint $table) {
            $table->dropForeign(['batch_id']);
            $table->dropColumn(['batch_id', 'batch_number', 'expiry_date', 'mfg_date', 'price']);
        });
    }
};
