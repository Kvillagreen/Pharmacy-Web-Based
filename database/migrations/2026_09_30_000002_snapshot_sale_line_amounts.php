<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
return new class extends Migration {
 public function up(): void {
  Schema::table('transaction_items', function (Blueprint $t) {
   $t->decimal('net_amount', 18, 2)->nullable();
   $t->decimal('output_vat', 18, 2)->nullable();
  });
  DB::statement('DROP VIEW IF EXISTS v_branch_tax_summary');
  DB::statement(\App\Services\v1\BranchTaxSummary::viewSql());
 }
 public function down(): void {
  DB::statement('DROP VIEW IF EXISTS v_branch_tax_summary');
  Schema::table('transaction_items', fn (Blueprint $t) => $t->dropColumn(['net_amount','output_vat']));
  DB::statement(\App\Services\v1\BranchTaxSummary::viewSql(false));
 }
};
