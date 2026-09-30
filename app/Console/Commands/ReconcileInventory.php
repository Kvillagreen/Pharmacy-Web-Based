<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\v1\ProductIdentity;
class ReconcileInventory extends Command {
    protected $signature = 'inventory:reconcile {--report= : JSON report path} {--apply : Apply exactly the reviewed derived totals} {--backup= : Existing SQL backup path}';
    protected $description = 'Audit authoritative inventory balances; never changes physical inventory rows or merges medicines';
    public function handle(): int {
        $path = $this->option('report');
        if (!$path) { $this->error('--report is required.'); return 1; }
        $rows = DB::table('medicines')->orderBy('medicine_id')->get()->map(function($medicine) {
            return ['medicine_id'=>$medicine->medicine_id,'previous'=>(int)$medicine->stocks,
                'derived'=>(int)DB::table('inventories')->where('medicine_id',$medicine->medicine_id)->sum('stocks'),
                'sku'=>ProductIdentity::sku((array)$medicine)];
        })->all();
        $digest = hash('sha256',json_encode($rows));
        if (!$this->option('apply')) {
            file_put_contents($path,json_encode(['created_at'=>now()->toIso8601String(),'digest'=>$digest,'rows'=>$rows],JSON_PRETTY_PRINT));
            $this->info(count(array_filter($rows,fn($r)=>$r['previous']!==$r['derived'])).' derived total differences. Report written. No quantities changed.'); return 0;
        }
        $backup=$this->option('backup');
        if (!$backup || !is_file($backup) || filesize($backup)<100 || !is_file($path)) { $this->error('A reviewed report and SQL backup are required.'); return 1; }
        $reviewed=json_decode(file_get_contents($path),true);
        if (($reviewed['digest']??'')!==$digest) { $this->error('Data changed since the report. Generate and review a new report.'); return 1; }
        DB::transaction(function() use($rows) {
            foreach($rows as $row) {
                DB::table('medicines')->where('medicine_id',$row['medicine_id'])->lockForUpdate()->first();
                $current=(int)DB::table('inventories')->where('medicine_id',$row['medicine_id'])->lockForUpdate()->get()->sum('stocks');
                if($current!==$row['derived']) throw new \RuntimeException('Stock changed during reconciliation. Retry with a fresh report.');
                DB::table('medicines')->where('medicine_id',$row['medicine_id'])->update(['stocks'=>$current,'sku'=>$row['sku']]);
            }
        });
        $this->info('Derived totals and SKUs reconciled. Inventory and historical records preserved.');return 0;
    }
}
