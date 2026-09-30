<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void { Schema::create('product_identity_keys',fn(Blueprint $t)=>$t->string('sku',40)->primary()); }
    public function down():void { Schema::dropIfExists('product_identity_keys'); }
};
