<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('transactions')
            ->whereRaw('LOWER(payment_method) = ?', ['maya'])
            ->delete();
    }

    public function down(): void
    {
        // Deleted unsupported payment records cannot be restored safely.
    }
};
