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
        Schema::create('transactions', function (Blueprint $table) {
            $table->bigIncrements('transaction_id');
            $table->unsignedBigInteger('transaction_type_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('branch_id');
            $table->decimal('total_amount', 10, 2);
            $table->string('payment_method');
            $table->decimal('sub_total', 10, 2);
            $table->decimal('change', 10, 2);
            $table->decimal('used_amount', 10, 2);
            $table->decimal('discount', 10, 2)->nullable()->default(0.00);
            $table->string('discount_type')->nullable();
            $table->string('scpwd_id_number')->nullable();

            $table->foreign('transaction_type_id')
                        ->references('transaction_type_id')
                        ->on('transaction_types')
                        ->onDelete('cascade')
                        ->onUpdate('cascade');

            $table->foreign('user_id')
                        ->references('user_id')
                        ->on('users')
                        ->onDelete('cascade')
                        ->onUpdate('cascade');

            $table->foreign('branch_id')
                        ->references('branch_id')
                        ->on('branches')
                        ->onDelete('cascade')
                        ->onUpdate('cascade');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
