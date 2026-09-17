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
        Schema::dropIfExists('mobile_users');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
         Schema::create('mobile_users', function (Blueprint $table) {
            $table->bigIncrements('mobile_user_id');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('address')->nullable();
            $table->enum('status', ['approved', 'blocked', 'deleted'])->default('approved');
            $table->timestamp('login_at')->nullable();
            $table->string('registered_ip')->nullable();
            $table->string('last_login_ip')->nullable();
            $table->string('last_seen_ip')->nullable();
            $table->boolean('notify_transactions')->default(true);
            $table->boolean('notify_low_stock')->default(true);
            $table->boolean('notify_expiry_alerts')->default(true);
            $table->boolean('notify_security_alerts')->default(true);
            $table->boolean('notify_browser')->default(true);
            $table->timestamp('last_password_changed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('company_id')->on('companies')->nullOnDelete();
            $table->foreign('branch_id')->references('branch_id')->on('branches')->nullOnDelete();
        });
    }
};
