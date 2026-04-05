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
        Schema::create('transaction_items', function (Blueprint $table) {
        $table->bigIncrements('transaction_item_id');
        $table->unsignedBigInteger('medicine_id');
        $table->unsignedBigInteger('transaction_id');
        $table->integer('quantity');

        $table->foreign('transaction_id')
                    ->references('transaction_id')
                    ->on('transactions')
                    ->onDelete('cascade')
                    ->onUpdate('cascade');

        $table->foreign('medicine_id')
                    ->references('medicine_id')
                    ->on('medicines')
                    ->onDelete('cascade')
                    ->onUpdate('cascade');
        $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_items');
    }
};
