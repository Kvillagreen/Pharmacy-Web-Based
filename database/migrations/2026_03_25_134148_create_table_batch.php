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
        Schema::create('batches', function (Blueprint $table) {
            $table->bigIncrements('batch_id'); // primary key
            $table->unsignedBigInteger('supplier_id'); // must be unsigned BIGINT
            $table->unsignedBigInteger('medicine_id');
            $table->date('expiry_date');
            $table->date('received_date');
            $table->enum('status', ['active', 'inactive', 'deleted'])->default('active');
            $table->timestamps();

           $table->foreign('supplier_id')
                    ->references('supplier_id')
                    ->on('suppliers')
                    ->onDelete('cascade')
                    ->onUpdate('cascade');

                $table->foreign('medicine_id')
                    ->references('medicine_id')
                    ->on('medicines')
                    ->onDelete('cascade')
                    ->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};
