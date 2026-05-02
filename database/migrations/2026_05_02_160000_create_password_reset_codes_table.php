<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_codes', function (Blueprint $table) {
    $table->bigIncrements('password_reset_code_id');
    $table->unsignedBigInteger('user_id');
    $table->string('email');
    $table->string('code', 6);

    $table->dateTime('expires_at');
    $table->dateTime('resend_available_at');
    $table->dateTime('used_at')->nullable();

    $table->timestamps();

    $table->foreign('user_id')
        ->references('user_id')
        ->on('users')
        ->cascadeOnDelete();

    $table->index(['email', 'used_at']);
});
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_codes');
    }
};
