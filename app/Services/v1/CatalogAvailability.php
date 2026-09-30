<?php
namespace App\Services\v1;
use Illuminate\Support\Facades\DB;
class CatalogAvailability {
    public function enrich(array $items, int $branchId = 0): array {
        $branches = DB::table('branches')->where('status','active')->when($branchId > 0, fn($q) => $q->where('branch_id',$branchId))->orderBy('branch_name')->get();
        if (!$items) return [];
        $allRows = DB::table('inventories as i')->join('medicines as m','i.medicine_id','=','m.medicine_id')
            ->join('batches as b','i.batch_id','=','b.batch_id')->whereNull('m.archived_at')
            ->whereIn('i.branch_id',$branches->pluck('branch_id'))->where(function($query)use($items){
                foreach($items as $item)$query->orWhere(function($match)use($item){
                    foreach(['medicine_name','generic_name','type','dosage','unit','units_per_box'] as $field)$match->where('m.'.$field,$item->$field);
                });
            })->get(['i.branch_id','i.stocks','m.price','b.status','b.expiry_date','m.medicine_name','m.generic_name','m.type','m.dosage','m.unit','m.units_per_box'])
            ->groupBy(fn($row)=>ProductIdentity::sku((array)$row));
        foreach ($items as $item) {
            $rows = $allRows->get(ProductIdentity::sku($item->getAttributes()),collect());
            $item->branch_availability = $branches->map(function($branch) use ($rows) {
                $carried = $rows->where('branch_id',$branch->branch_id);
                $eligible = $carried->filter(fn($row) => $row->status === 'active' && (!$row->expiry_date || substr($row->expiry_date,0,10) > now()->toDateString()));
                $stock = (int) $eligible->sum('stocks');
                $priced = $eligible->where('stocks','>',0);
                if ($priced->isEmpty()) $priced = $eligible;
                return ['branch_id'=>(int)$branch->branch_id,'name'=>$branch->branch_name,'contact'=>$branch->branch_contact,
                    'sellable_quantity'=>$stock,'unit_price'=>$priced->isEmpty()?null:(float)$priced->min('price'),
                    'price_max'=>$priced->isEmpty()?null:(float)$priced->max('price'),
                    'status'=>$carried->isEmpty()?'not_carried':($stock>0?'in_stock':'out_of_stock')];
            })->all();
            $available = collect($item->branch_availability)->where('status','in_stock');
            $item->price_min = $available->min('unit_price');
            $item->price_max = $available->max('price_max');
        }
        return $items;
    }
}
