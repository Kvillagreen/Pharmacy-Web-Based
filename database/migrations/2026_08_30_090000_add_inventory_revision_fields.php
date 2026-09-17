<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->unsignedInteger('units_per_box')->default(1)->after('unit');
            $table->timestamp('archived_at')->nullable()->after('needs_protection');
            $table->index('archived_at');
        });

        DB::statement("ALTER TABLE batches MODIFY status ENUM('active', 'inactive', 'archived', 'deleted', 'pulled_out', 'disposed') NOT NULL DEFAULT 'active'");

        Schema::create('batch_histories', function (Blueprint $table) {
            $table->bigIncrements('batch_history_id');
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('medicine_id')->nullable();
            $table->unsignedBigInteger('inventory_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 50);
            $table->integer('quantity_change')->default(0);
            $table->integer('stock_after')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('batch_id')->references('batch_id')->on('batches')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign('medicine_id')->references('medicine_id')->on('medicines')->nullOnDelete()->cascadeOnUpdate();
            $table->foreign('inventory_id')->references('inventory_id')->on('inventories')->nullOnDelete()->cascadeOnUpdate();
            $table->foreign('branch_id')->references('branch_id')->on('branches')->nullOnDelete()->cascadeOnUpdate();
            $table->foreign('user_id')->references('user_id')->on('users')->nullOnDelete()->cascadeOnUpdate();
            $table->index(['batch_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_histories');

        Schema::table('medicines', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);
            $table->dropColumn(['units_per_box', 'archived_at']);
        });

        DB::statement("UPDATE batches SET status = 'inactive' WHERE status = 'archived'");
        DB::statement("ALTER TABLE batches MODIFY status ENUM('active', 'inactive', 'deleted', 'pulled_out', 'disposed') NOT NULL DEFAULT 'active'");
    }
};
