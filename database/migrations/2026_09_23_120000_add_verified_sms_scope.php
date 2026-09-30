<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            // Old imports used the viewer's branch, which is not trustworthy routing evidence.
            $table->boolean('scope_verified')->default(false);
            $table->index(['branch_id', 'scope_verified', 'is_deleted', 'provider_received_at'], 'sms_branch_history_index');
        });
    }

    public function down(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropIndex('sms_branch_history_index');
            $table->dropColumn('scope_verified');
        });
    }
};
