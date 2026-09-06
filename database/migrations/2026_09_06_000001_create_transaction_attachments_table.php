<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_attachments', function (Blueprint $table) {
            $table->bigIncrements('transaction_attachment_id');
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('category', 40);
            $table->string('label', 120)->nullable();
            $table->uuid('remote_uuid')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('status', 40)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->foreign('transaction_id')
                ->references('transaction_id')
                ->on('transactions')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('uploaded_by')
                ->references('user_id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->index(['transaction_id', 'category'], 'txn_attachments_transaction_category_idx');
            $table->index(['remote_uuid'], 'txn_attachments_remote_uuid_idx');
            $table->index(['status'], 'txn_attachments_status_idx');
        });

        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'prescription_file_uuid')) {
                $table->uuid('prescription_file_uuid')->nullable()->after('prescription_path');
            }

            if (!Schema::hasColumn('transactions', 'member_id_image_file_uuid')) {
                $table->uuid('member_id_image_file_uuid')->nullable()->after('member_id_image_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'prescription_file_uuid',
                'member_id_image_file_uuid',
            ]);
        });

        Schema::dropIfExists('transaction_attachments');
    }
};
