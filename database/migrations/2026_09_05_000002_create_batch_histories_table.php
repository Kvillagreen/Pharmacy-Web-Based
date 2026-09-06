<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batch_histories', function (Blueprint $table) {
            $table->bigIncrements('batch_history_id');
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('batch_id')->references('batch_id')->on('batches')->cascadeOnDelete();
            $table->foreign('user_id')->references('user_id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_histories');
    }
};
