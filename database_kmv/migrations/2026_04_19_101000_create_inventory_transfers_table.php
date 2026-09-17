<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->bigIncrements('inventory_transfer_id');
            $table->unsignedBigInteger('medicine_id');
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('from_branch_id');
            $table->unsignedBigInteger('to_branch_id');
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->integer('quantity');
            $table->text('notes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->enum('status', ['pending', 'accepted', 'declined'])->default('pending');
            $table->timestamps();

            $table->foreign('medicine_id')->references('medicine_id')->on('medicines')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('batch_id')->references('batch_id')->on('batches')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('from_branch_id')->references('branch_id')->on('branches')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('to_branch_id')->references('branch_id')->on('branches')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('requested_by')->references('user_id')->on('users')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('resolved_by')->references('user_id')->on('users')->nullOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfers');
    }
};
