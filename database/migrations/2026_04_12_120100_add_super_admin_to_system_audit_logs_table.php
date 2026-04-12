<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('super_admin_id')->nullable()->after('user_id');

            $table->foreign('super_admin_id')
                ->references('super_admin_id')
                ->on('super_admins')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('system_audit_logs', function (Blueprint $table) {
            $table->dropForeign(['super_admin_id']);
            $table->dropColumn('super_admin_id');
        });
    }
};
