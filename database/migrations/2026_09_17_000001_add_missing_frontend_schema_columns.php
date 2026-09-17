<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ensure the live database still matches the frontend contract.
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            if (!Schema::hasColumn('branches', 'theme_key')) {
                $table->string('theme_key', 50)->default('emerald')->after('branch_contact');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $missingPreferenceColumns = [
                'notify_low_stock' => ['type' => 'boolean', 'default' => true],
                'notify_expiry_alerts' => ['type' => 'boolean', 'default' => true],
                'notify_security_alerts' => ['type' => 'boolean', 'default' => true],
                'notify_browser' => ['type' => 'boolean', 'default' => true],
            ];

            foreach ($missingPreferenceColumns as $column => $definition) {
                if (!Schema::hasColumn('users', $column)) {
                    $table->boolean($column)->default($definition['default']);
                }
            }

            if (!Schema::hasColumn('users', 'last_password_changed_at')) {
                $table->timestamp('last_password_changed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['notify_low_stock', 'notify_expiry_alerts', 'notify_security_alerts', 'notify_browser', 'last_password_changed_at'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('branches', function (Blueprint $table) {
            if (Schema::hasColumn('branches', 'theme_key')) {
                $table->dropColumn('theme_key');
            }
        });
    }
};
