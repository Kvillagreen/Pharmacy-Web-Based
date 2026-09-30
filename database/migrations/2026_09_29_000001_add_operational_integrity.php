<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('medicines', function (Blueprint $t) { $t->string('sku', 40)->nullable()->index(); });
        Schema::table('transactions', function (Blueprint $t) { $t->string('request_fingerprint', 64)->nullable(); });
        Schema::table('transaction_items', function (Blueprint $t) { $t->string('product_sku', 40)->nullable(); });
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('inventory_id')->index(); $t->unsignedBigInteger('medicine_id')->index();
            $t->unsignedBigInteger('branch_id')->index(); $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('action', 50); $t->integer('quantity_change'); $t->integer('stock_before'); $t->integer('stock_after');
            $t->string('reference', 255)->nullable(); $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('tax_profiles', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id')->index(); $t->unsignedInteger('version');
            $t->decimal('tax_rate_percent', 6, 3)->nullable(); $t->date('effective_from');
            $t->string('accountant_name')->nullable(); $t->text('approval_reference')->nullable();
            $t->timestamp('approved_at')->nullable(); $t->unsignedBigInteger('created_by'); $t->timestamps();
            $t->unique(['branch_id', 'version']);
        });
        Schema::create('expense_entries', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id')->index(); $t->date('expense_date');
            $t->string('description'); $t->decimal('amount', 15, 2); $t->boolean('allowable')->default(false);
            $t->string('reference')->nullable(); $t->unsignedBigInteger('created_by'); $t->timestamps();
            $t->softDeletes(); $t->index(['branch_id', 'expense_date']);
        });
        Schema::create('report_snapshots', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('branch_id'); $t->unsignedInteger('year'); $t->unsignedInteger('version');
            $t->unsignedBigInteger('created_by'); $t->unsignedBigInteger('tax_profile_id')->nullable();
            $t->json('inputs'); $t->json('output'); $t->string('checksum', 64); $t->timestamps();
            $t->unique(['branch_id', 'year', 'version']);
        });
        Schema::table('inventories', fn (Blueprint $t) => $t->index(['branch_id','medicine_id','batch_id'], 'inventory_scope_lookup'));
    }
    public function down(): void {
        Schema::table('inventories', fn (Blueprint $t) => $t->dropIndex('inventory_scope_lookup'));
        foreach (['report_snapshots','expense_entries','tax_profiles','stock_movements'] as $table) Schema::dropIfExists($table);
        Schema::table('transaction_items', fn (Blueprint $t) => $t->dropColumn('product_sku'));
        Schema::table('transactions', fn (Blueprint $t) => $t->dropColumn('request_fingerprint'));
        Schema::table('medicines', fn (Blueprint $t) => $t->dropColumn('sku'));
    }
};
