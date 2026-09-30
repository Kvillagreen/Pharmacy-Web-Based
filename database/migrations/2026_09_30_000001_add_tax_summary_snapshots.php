<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
return new class extends Migration {
 public function up():void {
  Schema::table('medicines',fn(Blueprint $t)=>$t->boolean('is_vat_exempt')->nullable());
  Schema::table('inventories',fn(Blueprint $t)=>$t->boolean('cost_includes_vat')->nullable());
  Schema::table('transaction_items',function(Blueprint $t){$t->boolean('is_vat_exempt')->nullable();$t->boolean('cost_includes_vat')->nullable();});
  Schema::table('transactions',fn(Blueprint $t)=>$t->index(['branch_id','status','created_at'],'transactions_tax_scope_idx'));
  DB::table('medicines')->whereNull('is_vat_exempt')->update(['is_vat_exempt' => 0]);
  DB::table('inventories')->whereNull('cost_includes_vat')->update(['cost_includes_vat' => 1]);
  DB::table('transaction_items')->whereNull('is_vat_exempt')->update(['is_vat_exempt' => 0]);
  DB::table('transaction_items')->whereNull('cost_includes_vat')->update(['cost_includes_vat' => 1]);
  DB::statement('DROP VIEW IF EXISTS v_branch_tax_summary');
  DB::statement(\App\Services\v1\BranchTaxSummary::viewSql(false));
 }
 public function down():void {
  DB::statement('DROP VIEW IF EXISTS v_branch_tax_summary');
  Schema::table('transactions',fn(Blueprint $t)=>$t->dropIndex('transactions_tax_scope_idx'));
  Schema::table('transaction_items',fn(Blueprint $t)=>$t->dropColumn(['is_vat_exempt','cost_includes_vat']));
  Schema::table('inventories',fn(Blueprint $t)=>$t->dropColumn('cost_includes_vat'));
  Schema::table('medicines',fn(Blueprint $t)=>$t->dropColumn('is_vat_exempt'));
 }
};
