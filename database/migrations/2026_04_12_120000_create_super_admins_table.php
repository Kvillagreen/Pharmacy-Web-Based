<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('super_admins', function (Blueprint $table) {
            $table->bigIncrements('super_admin_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('address')->nullable();
            $table->timestamp('login_at')->nullable();
            $table->string('registered_ip', 45)->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->string('last_seen_ip', 45)->nullable();
            $table->unsignedBigInteger('created_by_super_admin_id')->nullable();
            $table->timestamps();

            $table->foreign('created_by_super_admin_id')
                ->references('super_admin_id')
                ->on('super_admins')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });

        if (Schema::hasTable('users') && DB::getDriverName() === 'mysql') {
            $legacySuperAdmins = DB::table('users')
                ->where('role', 'super_admin')
                ->get();

            foreach ($legacySuperAdmins as $legacyAdmin) {
                $exists = DB::table('super_admins')
                    ->where('email', $legacyAdmin->email)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $password = (string) $legacyAdmin->password;
                $passwordInfo = password_get_info($password);

                DB::table('super_admins')->insert([
                    'first_name' => $legacyAdmin->first_name,
                    'last_name' => $legacyAdmin->last_name,
                    'email' => $legacyAdmin->email,
                    'password' => $passwordInfo['algo'] !== null ? $password : Hash::make($password),
                    'address' => $legacyAdmin->address,
                    'login_at' => $legacyAdmin->login_at,
                    'registered_ip' => $legacyAdmin->registered_ip ?? null,
                    'last_login_ip' => $legacyAdmin->last_login_ip ?? null,
                    'last_seen_ip' => $legacyAdmin->last_seen_ip ?? null,
                    'created_at' => $legacyAdmin->created_at,
                    'updated_at' => $legacyAdmin->updated_at,
                ]);
            }

            DB::table('users')->where('role', 'super_admin')->delete();
            DB::statement("ALTER TABLE users MODIFY role ENUM('admin', 'pharmacist', 'inventory', 'user', 'manager') NOT NULL");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('super_admin', 'admin', 'pharmacist', 'inventory', 'user', 'manager') NOT NULL");
        }

        Schema::dropIfExists('super_admins');
    }
};
