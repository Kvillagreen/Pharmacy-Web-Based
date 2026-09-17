<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('claim_status')->default('not_applicable')->after('documents_submitted');
            $table->decimal('claim_amount_covered', 10, 2)->nullable()->after('claim_status');
            $table->timestamp('documents_completed_at')->nullable()->after('claim_amount_covered');
        });

        Schema::create('transaction_claim_updates', function (Blueprint $table) {
            $table->bigIncrements('transaction_claim_update_id');
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('update_type', 50);
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('transaction_id')
                ->references('transaction_id')
                ->on('transactions')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('user_id')
                ->references('user_id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });

        Schema::create('transaction_claim_notes', function (Blueprint $table) {
            $table->bigIncrements('transaction_claim_note_id');
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('note');
            $table->timestamps();

            $table->foreign('transaction_id')
                ->references('transaction_id')
                ->on('transactions')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('user_id')
                ->references('user_id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });

        DB::table('permissions')->updateOrInsert(
            ['permission_name' => 'claims'],
            ['description' => 'Can access HMO and PhilHealth claims page']
        );

        DB::table('transactions')
            ->whereIn('transaction_type', ['hmo', 'philhealth'])
            ->update([
                'claim_status' => DB::raw("CASE WHEN documents_submitted = 1 THEN 'documents_ready' ELSE 'pending_documents' END"),
                'documents_completed_at' => DB::raw("CASE WHEN documents_submitted = 1 THEN COALESCE(updated_at, created_at) ELSE NULL END"),
            ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_claim_notes');
        Schema::dropIfExists('transaction_claim_updates');

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'claim_status',
                'claim_amount_covered',
                'documents_completed_at',
            ]);
        });

        DB::table('permissions')
            ->where('permission_name', 'claims')
            ->delete();
    }
};
