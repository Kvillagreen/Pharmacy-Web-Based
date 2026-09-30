<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        \Illuminate\Support\Facades\Schema::table('batches', fn (\Illuminate\Database\Schema\Blueprint $table) => $table->enum('status', ['active', 'inactive', 'deleted', 'pulled_out', 'disposed', 'archived'])->default('active')->change());
    }

    public function down(): void
    {
        DB::statement("
            UPDATE batches
            SET status = 'deleted'
            WHERE status = 'archived'
        ");

        \Illuminate\Support\Facades\Schema::table('batches', fn (\Illuminate\Database\Schema\Blueprint $table) => $table->enum('status', ['active', 'inactive', 'deleted', 'pulled_out', 'disposed'])->default('active')->change());
    }
};
