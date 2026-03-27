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
            Schema::create('inventories', function (Blueprint $table) {
                $table->bigIncrements('inventory_id'); // primary key
                $table->unsignedBigInteger('branch_id'); // foreign key
                $table->unsignedBigInteger('medicine_id'); // foreign key
                $table->unsignedBigInteger('batch_id'); // foreign key
                $table->unsignedBigInteger('quantity_on_hand'); // quantity, non-negative
                $table->timestamps();

                // Foreign keys
                $table->foreign('branch_id')
                    ->references('branch_id')
                    ->on('branches')
                    ->onDelete('cascade')
                    ->onUpdate('cascade');

                $table->foreign('medicine_id')
                    ->references('medicine_id')
                    ->on('medicines')
                    ->onDelete('cascade')
                    ->onUpdate('cascade');

                $table->foreign('batch_id')
                    ->references('batch_id')
                    ->on('batches')
                    ->onDelete('cascade')
                    ->onUpdate('cascade');
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};
