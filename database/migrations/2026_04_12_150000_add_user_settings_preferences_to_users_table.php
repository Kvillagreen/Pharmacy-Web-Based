<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_transactions')->default(true)->after('last_seen_ip');
            $table->boolean('notify_user_registrations')->default(true)->after('notify_transactions');
            $table->boolean('notify_low_stock')->default(true)->after('notify_user_registrations');
            $table->boolean('notify_expiry_alerts')->default(true)->after('notify_low_stock');
            $table->boolean('notify_security_alerts')->default(true)->after('notify_expiry_alerts');
            $table->boolean('notify_browser')->default(true)->after('notify_security_alerts');
            $table->timestamp('last_password_changed_at')->nullable()->after('notify_browser');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'notify_transactions',
                'notify_user_registrations',
                'notify_low_stock',
                'notify_expiry_alerts',
                'notify_security_alerts',
                'notify_browser',
                'last_password_changed_at',
            ]);
        });
    }
};
