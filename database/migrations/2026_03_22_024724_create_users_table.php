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
        Schema::create('users', function (Blueprint $table) {
        $table->bigIncrements('user_id'); // primary key
        $table->unsignedBigInteger('branch_id'); // must be unsigned BIGINT
        $table->string('first_name');
        $table->string('last_name');
        $table->string('email')->unique();
        $table->string('password');
        $table->enum('status', ['pending', 'approved', 'deleted', 'rejected'])
            ->default('pending');
        $table->enum('role', ['admin', 'pharmacist', 'inventory', 'user']);
        $table->string('address')->nullable();
        $table->timestamps();

        $table->foreign('branch_id')->references('branch_id')->on('branches');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
