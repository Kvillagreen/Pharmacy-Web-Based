<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_attachments', function (Blueprint $table) {
            if (!Schema::hasColumn('transaction_attachments', 'remote_file_id')) {
                $table->string('remote_file_id', 80)->nullable()->after('label');
            }

            if (!Schema::hasColumn('transaction_attachments', 'remote_file_name')) {
                $table->string('remote_file_name')->nullable()->after('remote_file_id');
            }
        });

        $convertedRemoteUuid = Schema::hasColumn('transaction_attachments', 'remote_uuid');

        if ($convertedRemoteUuid) {
            DB::table('transaction_attachments')
                ->whereNull('remote_file_id')
                ->update(['remote_file_id' => DB::raw('remote_uuid')]);

            Schema::table('transaction_attachments', function (Blueprint $table) {
                $table->dropIndex('txn_attachments_remote_uuid_idx');
                $table->dropColumn('remote_uuid');
            });
        }

        if ($convertedRemoteUuid) {
            Schema::table('transaction_attachments', function (Blueprint $table) {
                $table->index(['remote_file_id'], 'txn_attachments_remote_file_id_idx');
            });
        }

        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'prescription_file_id')) {
                $table->string('prescription_file_id', 80)->nullable()->after('prescription_path');
            }

            if (!Schema::hasColumn('transactions', 'member_id_image_file_id')) {
                $table->string('member_id_image_file_id', 80)->nullable()->after('member_id_image_path');
            }
        });

        if (Schema::hasColumn('transactions', 'prescription_file_uuid')) {
            DB::table('transactions')
                ->whereNull('prescription_file_id')
                ->update(['prescription_file_id' => DB::raw('prescription_file_uuid')]);
        }

        if (Schema::hasColumn('transactions', 'member_id_image_file_uuid')) {
            DB::table('transactions')
                ->whereNull('member_id_image_file_id')
                ->update(['member_id_image_file_id' => DB::raw('member_id_image_file_uuid')]);
        }

        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'prescription_file_uuid')) {
                $table->dropColumn('prescription_file_uuid');
            }

            if (Schema::hasColumn('transactions', 'member_id_image_file_uuid')) {
                $table->dropColumn('member_id_image_file_uuid');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'prescription_file_id')) {
                $table->dropColumn('prescription_file_id');
            }

            if (Schema::hasColumn('transactions', 'member_id_image_file_id')) {
                $table->dropColumn('member_id_image_file_id');
            }
        });

        Schema::table('transaction_attachments', function (Blueprint $table) {
            if (Schema::hasColumn('transaction_attachments', 'remote_file_id')) {
                $table->dropIndex('txn_attachments_remote_file_id_idx');
                $table->dropColumn(['remote_file_id', 'remote_file_name']);
            }
        });
    }
};
