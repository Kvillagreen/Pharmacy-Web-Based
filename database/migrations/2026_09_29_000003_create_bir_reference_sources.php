<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('bir_reference_sources',function(Blueprint $t){
  $t->id();$t->unsignedBigInteger('branch_id')->index();$t->unsignedBigInteger('created_by');
  $t->string('identity_key',64);$t->string('source_key',64)->unique();$t->string('source_reference',150);
  $t->string('pharmacy_role',10);$t->date('period_from');$t->date('period_to');
  $t->json('payee');$t->json('payor');$t->json('line');$t->json('optional_details')->nullable();
  $t->string('status',20)->default('active');$t->text('void_reason')->nullable();$t->timestamps();
  $t->index(['branch_id','identity_key','period_from','period_to'],'bir_reference_lookup');
 }); }
 public function down(): void { Schema::dropIfExists('bir_reference_sources'); }
};
