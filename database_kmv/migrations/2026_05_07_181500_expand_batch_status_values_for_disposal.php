<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE batches
            MODIFY status ENUM('active', 'inactive', 'deleted', 'pulled_out', 'disposed')
            NOT NULL DEFAULT 'active'
        ");
    }

    public function down(): void
    {
        DB::statement("
            UPDATE batches
            SET status = 'deleted'
            WHERE status IN ('pulled_out', 'disposed')
        ");

        DB::statement("
            ALTER TABLE batches
            MODIFY status ENUM('active', 'inactive', 'deleted')
            NOT NULL DEFAULT 'active'
        ");
    }
};
