<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
class ReviewProductIdentity extends Command {
    protected $signature='inventory:review-products {--report= : Output JSON path}';
    protected $description='Review identity conflicts and historical reference counts without merging or deleting records';
    public function handle():int {
        $rows=DB::table('medicines')->orderBy('medicine_id')->get();
        $duplicates=$rows->filter(fn($r)=>filled($r->sku))->groupBy('sku')->filter(fn($rows)=>$rows->count()>1);
        $conflicts=$rows->filter(fn($r)=>str_contains(strtolower($r->medicine_name),'softgel') && strtolower(trim($r->type))!=='softgel');
        $ids=$duplicates->flatten()->pluck('medicine_id')->merge($conflicts->pluck('medicine_id'))->unique();
        $review=$rows->whereIn('medicine_id',$ids)->map(fn($r)=>[
            'medicine_id'=>$r->medicine_id,'sku'=>$r->sku,'name'=>$r->medicine_name,'generic'=>$r->generic_name,'form'=>$r->type,
            'strength'=>$r->dosage.' '.$r->unit,'pack_size'=>$r->units_per_box,
            'inventory_references'=>DB::table('inventories')->where('medicine_id',$r->medicine_id)->count(),
            'transaction_item_references'=>DB::table('transaction_items')->where('medicine_id',$r->medicine_id)->count(),
            'review_reason'=>$duplicates->has($r->sku)?'Duplicate identity; validate all historical references before merging.':'Name/form conflict; confirm the physical product before editing.',
        ])->values();
        $json=json_encode(['generated_at'=>now()->toIso8601String(),'duplicate_groups'=>$duplicates->count(),'review'=>$review],JSON_PRETTY_PRINT);
        if($this->option('report'))file_put_contents($this->option('report'),$json);else $this->line($json);
        return 0;
    }
}
