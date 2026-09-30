<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::table('companies',fn(Blueprint $t)=>$t->json('tax_profile')->nullable()); }
 public function down(): void { Schema::table('companies',fn(Blueprint $t)=>$t->dropColumn('tax_profile')); }
};
