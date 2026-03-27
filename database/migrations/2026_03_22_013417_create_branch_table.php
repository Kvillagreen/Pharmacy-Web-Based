<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->bigIncrements('branch_id'); // primary key
            $table->string('branch_name');
            $table->string('branch_address')->nullable();
            $table->string('branch_contact')->nullable();
            $table->enum('status', ['active', 'inactive', 'deleted'])
                  ->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
