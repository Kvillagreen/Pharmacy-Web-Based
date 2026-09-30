<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::table('sms_orders',function(Blueprint $t){$t->unsignedBigInteger('transaction_id')->nullable()->unique();$t->unsignedBigInteger('confirmed_by')->nullable();$t->timestamp('confirmed_at')->nullable();$t->unsignedBigInteger('picked_up_by')->nullable();$t->timestamp('picked_up_at')->nullable();});}
 public function down():void {Schema::table('sms_orders',fn(Blueprint $t)=>$t->dropColumn(['transaction_id','confirmed_by','confirmed_at','picked_up_by','picked_up_at']));}
};
