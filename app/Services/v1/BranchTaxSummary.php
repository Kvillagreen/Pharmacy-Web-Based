<?php
namespace App\Services\v1;
use Illuminate\Support\Facades\DB;
final class BranchTaxSummary {
 public static function graduated(float $income): array {
  $income=round(max(0,$income),2);
  foreach ([[250000,0,0,0],[400000,250000,0,.15],[800000,400000,22500,.20],[2000000,800000,102500,.25],[8000000,2000000,402500,.30],[INF,8000000,2202500,.35]] as [$ceiling,$floor,$base,$rate]) {
   if($income<=$ceiling)return ['floor'=>$floor,'base_tax'=>$base,'marginal_rate'=>$rate,'excess'=>round(max(0,$income-$floor),2),'tax_due'=>round($base+max(0,$income-$floor)*$rate,2)];
  }
  throw new \LogicException('Invalid tax income');
 }
 public static function viewSql(bool $snapshots = true): string {
  $year=DB::getDriverName()==='sqlite'?"CAST(strftime('%Y',t.created_at) AS INTEGER)":"YEAR(t.created_at)";
  $quarter=DB::getDriverName()==='sqlite'?"CAST((CAST(strftime('%m',t.created_at) AS INTEGER)+2)/3 AS INTEGER)":"QUARTER(t.created_at)";
  $legacyNet = 'CASE WHEN totals.gross>0 THEN (ti.quantity*ti.price)*t.total_amount/totals.gross ELSE 0 END';
  $net = $snapshots ? "COALESCE(ti.net_amount,$legacyNet)" : $legacyNet;
  $vat = $snapshots ? 'ti.output_vat' : 'NULL';
  return "CREATE VIEW v_branch_tax_summary AS SELECT branch_id, taxable_year,taxable_quarter,
   COUNT(DISTINCT transaction_id) AS transaction_count,
   SUM(gross) AS gross_sales,SUM(gross-net) AS discounts,SUM(net) AS receipts,
   SUM(net-COALESCE(recorded_vat,CASE WHEN exempt=1 THEN 0 ELSE net-net/1.12 END)) AS net_sales_revenue,
   SUM(COALESCE(recorded_vat,CASE WHEN exempt=1 THEN 0 ELSE net-net/1.12 END)) AS output_vat,
   SUM(CASE WHEN exempt=1 THEN net ELSE 0 END) AS exempt_sales,
   SUM(cost) AS gross_cost,
   SUM(CASE WHEN exempt=1 OR cost_vat=0 THEN cost ELSE cost/1.12 END) AS cost_of_sales,
   SUM(CASE WHEN exempt=1 OR cost_vat=0 THEN 0 ELSE cost-cost/1.12 END) AS input_vat,
   SUM(CASE WHEN exempt IS NULL OR (exempt<>1 AND cost_vat IS NULL) OR cost_missing=1 THEN 1 ELSE 0 END) AS unverified_lines
   FROM (SELECT t.branch_id,t.transaction_id,$year AS taxable_year,$quarter AS taxable_quarter,
    ti.quantity*ti.price AS gross,
    $net AS net, $vat AS recorded_vat,
    ti.quantity*COALESCE(ti.cost_price,0) AS cost,
    CASE WHEN LOWER(COALESCE(t.discount_type,'')) IN ('senior','pwd','scpwd') THEN 1 ELSE ti.is_vat_exempt END AS exempt,
    ti.cost_includes_vat AS cost_vat,CASE WHEN ti.cost_price IS NULL THEN 1 ELSE 0 END AS cost_missing
    FROM transactions t JOIN transaction_items ti ON ti.transaction_id=t.transaction_id
    JOIN (SELECT transaction_id,SUM(quantity*price) AS gross FROM transaction_items GROUP BY transaction_id) totals ON totals.transaction_id=t.transaction_id
    WHERE t.status='completed') line GROUP BY branch_id,taxable_year,taxable_quarter";
 }
 public static function totals(array $branches,int $year,int $quarter=4,bool $vat=true): array {
  $rows=DB::table('v_branch_tax_summary')->whereIn('branch_id',$branches)->where('taxable_year',$year)->where('taxable_quarter','<=',$quarter)->orderBy('taxable_quarter')->get();
  $fields=['gross_sales','discounts','receipts','net_sales_revenue','output_vat','exempt_sales','gross_cost','cost_of_sales','input_vat','unverified_lines','transaction_count'];$out=[];
  foreach($fields as $field)$out[$field]=round((float)$rows->sum($field),2);
  if(!$vat){$out['net_sales_revenue']=$out['receipts'];$out['cost_of_sales']=$out['gross_cost'];$out['output_vat']=$out['input_vat']=0;}
  $lines=DB::table('transaction_items as ti')->join('transactions as t','t.transaction_id','=','ti.transaction_id')
   ->whereIn('t.branch_id',$branches)->where('t.status','completed')->whereYear('t.created_at',$year)
   ->whereMonth('t.created_at','<=',$quarter*3);
  $statutory="LOWER(COALESCE(t.discount_type,'')) IN ('senior','pwd','scpwd')";
  $evidence=(clone $lines)->selectRaw("SUM(CASE WHEN ti.is_vat_exempt IS NULL AND NOT ($statutory) THEN 1 ELSE 0 END) AS unknown_sales_vat,
   SUM(CASE WHEN ti.cost_price IS NULL THEN 1 ELSE 0 END) AS missing_cost,
   SUM(CASE WHEN ti.cost_includes_vat IS NULL AND COALESCE(ti.is_vat_exempt,0)<>1 AND NOT ($statutory) THEN 1 ELSE 0 END) AS unknown_cost_vat,
   SUM(CASE WHEN ti.quantity<=0 OR ti.price<0 OR ti.cost_price<0 OR ti.net_amount<0 OR ti.output_vat<0 OR ti.output_vat>ti.net_amount THEN 1 ELSE 0 END) AS invalid_lines,
   SUM(CASE WHEN ti.output_vat>0 THEN 1 ELSE 0 END) AS recorded_vat_lines")->first();
  $out['sales_evidence_missing']=$vat?(int)$evidence->unknown_sales_vat:0;
  $out['cost_evidence_missing']=(int)$evidence->missing_cost+($vat?(int)$evidence->unknown_cost_vat:0);
  $out['invalid_lines']=(int)$evidence->invalid_lines;
  $out['vat_profile_conflicts']=$vat?0:(int)$evidence->recorded_vat_lines;
  $mismatches=(clone $lines)->select('t.transaction_id')->groupBy('t.transaction_id','t.total_amount')
   ->havingRaw('(COUNT(ti.net_amount)=COUNT(*) AND ABS(SUM(ti.net_amount)-t.total_amount)>0.01) OR (SUM(ti.quantity*ti.price)=0 AND t.total_amount<>0)');
  $out['unreconciled_transactions']=DB::query()->fromSub($mismatches,'unreconciled')->count();
  $out['quarters']=$rows->groupBy('taxable_quarter')->map(fn($r,$q)=>['quarter'=>(int)$q,'receipts'=>round($r->sum('receipts'),2),'net_sales'=>round($r->sum($vat?'net_sales_revenue':'receipts'),2),'output_vat'=>$vat?round($r->sum('output_vat'),2):0,'cost_of_sales'=>round($r->sum($vat?'cost_of_sales':'gross_cost'),2)])->values()->all();
  return $out;
 }
}
