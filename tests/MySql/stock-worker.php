<?php
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config('database.default')!=='mysql'||!str_starts_with(config('database.connections.mysql.database'),'pharmacy_test_'))exit(90);
try {
    Illuminate\Support\Facades\DB::transaction(function()use($argv){
        $inventory=App\Models\v1\Inventory::whereKey((int)$argv[1])->lockForUpdate()->firstOrFail();
        usleep(250000);
        app(App\Services\v1\StockMovementService::class)->change($inventory,-1,'concurrency_test','isolated test');
    },3);
    echo 'sold';
}catch(Illuminate\Validation\ValidationException $error){echo 'rejected';}
