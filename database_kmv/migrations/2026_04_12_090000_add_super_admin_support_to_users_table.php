<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('registered_ip', 45)->nullable()->after('login_at');
            $table->string('last_login_ip', 45)->nullable()->after('registered_ip');
            $table->string('last_seen_ip', 45)->nullable()->after('last_login_ip');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('super_admin', 'admin', 'pharmacist', 'inventory', 'user', 'manager') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('admin', 'pharmacist', 'inventory', 'user', 'manager') NOT NULL");
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['registered_ip', 'last_login_ip', 'last_seen_ip']);
        });
    }
};
