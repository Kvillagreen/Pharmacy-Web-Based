<?php
require __DIR__.'/../vendor/autoload.php';$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config('database.default')!=='mysql'||config('database.connections.mysql.database')!=='pharmacy_test_browser')throw new RuntimeException('Only the isolated browser-test database may be seeded.');
if (Illuminate\Support\Facades\Artisan::call('migrate:fresh',['--force'=>true]) !== 0) throw new RuntimeException('Isolated browser migrations failed.');
$branch=App\Models\v1\Branch::factory()->create(['branch_name'=>'Browser Test Main']);
$owner=App\Models\v1\User::factory()->create(['branch_id'=>$branch->branch_id,'email'=>'owner@browser.test','password'=>Illuminate\Support\Facades\Hash::make('Browser-test-only-2026!'),'role'=>'owner','status'=>'approved','manager_pin_hash'=>Illuminate\Support\Facades\Hash::make('5678')]);
$medicine=App\Models\v1\Medicine::factory()->create(['medicine_name'=>'Browser Test Medicine','price'=>10,'stocks'=>5,'units_per_box'=>10,'needs_protection'=>false,'is_dangerous'=>false]);
$batch=App\Models\v1\Batch::factory()->create(['status'=>'active','expiry_date'=>now()->addYear()->toDateString()]);
App\Models\v1\Inventory::create(['branch_id'=>$branch->branch_id,'medicine_id'=>$medicine->medicine_id,'batch_id'=>$batch->batch_id,'stocks'=>5,'cost_price'=>5]);
echo "Isolated browser fixtures ready.\n";
