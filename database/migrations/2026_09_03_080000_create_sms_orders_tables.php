<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_orders', function (Blueprint $table) {
            $table->bigIncrements('sms_order_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('customer_number', 30)->index();
            $table->text('message_body');
            $table->string('provider_message_id')->nullable()->unique();
            $table->string('status', 20)->default('pending')->index();
            $table->decimal('total_price', 12, 2);
            $table->timestamps();

            $table->foreign('branch_id')->references('branch_id')->on('branches')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('sms_order_items', function (Blueprint $table) {
            $table->bigIncrements('sms_order_item_id');
            $table->unsignedBigInteger('sms_order_id');
            $table->unsignedBigInteger('medicine_id');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();

            $table->foreign('sms_order_id')->references('sms_order_id')->on('sms_orders')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign('medicine_id')->references('medicine_id')->on('medicines')->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_order_items');
        Schema::dropIfExists('sms_orders');
    }
};
