<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('manager_pin_hash')->nullable()->after('password');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->text('void_reason')->nullable()->after('status');
            $table->text('void_supervisor_note')->nullable()->after('void_reason');
            $table->timestamp('voided_at')->nullable()->after('void_supervisor_note');
            $table->unsignedBigInteger('voided_by_user_id')->nullable()->after('voided_at');
            $table->unsignedBigInteger('void_authorized_by_user_id')->nullable()->after('voided_by_user_id');

            $table->foreign('voided_by_user_id')
                ->references('user_id')->on('users')->nullOnDelete()->cascadeOnUpdate();
            $table->foreign('void_authorized_by_user_id')
                ->references('user_id')->on('users')->nullOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['voided_by_user_id']);
            $table->dropForeign(['void_authorized_by_user_id']);
            $table->dropColumn([
                'void_reason',
                'void_supervisor_note',
                'voided_at',
                'voided_by_user_id',
                'void_authorized_by_user_id',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('manager_pin_hash');
        });
    }
};
