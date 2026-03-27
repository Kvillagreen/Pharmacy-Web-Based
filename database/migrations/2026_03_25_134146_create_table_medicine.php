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
        Schema::create('medicines', function (Blueprint $table) {
        $table->bigIncrements('medicine_id');
        $table->string('medicine_name');
        $table->string('generic_name');
        $table->string('category');
        $table->decimal('price', 10, 2);
        $table->integer('reorder_level');
        $table->boolean('is_dangerous')->default(false);
        $table->boolean('needs_protection')->default(false);
        $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medicines');
    }
};
