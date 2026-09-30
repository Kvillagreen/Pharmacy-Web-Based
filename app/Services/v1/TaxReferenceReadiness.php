<?php
namespace App\Services\v1;

final class TaxReferenceReadiness
{
 public static function apply(array $data, array $input, ?array $profile): array {
  $issues=[]; $vat=$data['vat'];
  if (!$profile || ($profile['entity']??'')!=='sole_proprietor') $issues[]='Confirm a supported sole-proprietor tax profile. No individual tax is calculated for an unconfirmed entity or corporation.';
  if ($data['scope_type']!=='company') $issues[]='Branch financial reference only. Income tax applies to the taxpayer across all branches; generate a company-wide report for a tax estimate.';
  if (!($input['taxpayer_scope_confirmed']??false)) $issues[]='Confirm that all taxpayer business income is included, including income outside this pharmacy.';
  if (empty($input['income_type'])) $issues[]='Select purely business income or mixed income.';
  if ($data['unitemized_transactions']>0) $issues[]=$data['unitemized_transactions'].' completed transactions have no item details. Reconcile them before calculating income tax.';
  if ($vat['sales_evidence_missing']>0) $issues[]=$vat['sales_evidence_missing'].' item lines have unknown VAT classification. Verify historical tax evidence; current medicine settings do not rewrite those sales.';
  if ($vat['cost_evidence_missing']>0 && $data['tax_rate_type']==='graduated') $issues[]=$vat['cost_evidence_missing'].' cost evidence checks are incomplete. Verify historical purchase costs and VAT treatment for itemized deductions.';
  if ($vat['invalid_lines']>0 || $vat['unreconciled_transactions']>0) $issues[]='Recorded sale lines do not reconcile or contain invalid amounts. Correct the source records before calculating tax.';
  if ($vat['vat_profile_conflicts']>0) $issues[]='The selected non-VAT treatment conflicts with VAT recorded on sales.';
  if (($input['income_type']??'')==='mixed_income' && $data['quarter']===null && $data['tax_rate_type']==='graduated' && !isset($input['taxable_compensation'])) $issues[]='Enter annual taxable compensation (from supporting records) for a mixed-income graduated calculation.';
  $data['tax_profile']=$profile;
  $data['calculation_issues']=$issues;
  $data['calculation_available']=count($issues)===0;
  $data['report_status']=$issues?'Draft — tax not calculated':'Internal tax estimate — not for submission';
  $data['is_provisional']=true;
  $data['data_notes']=array_merge($issues,$data['data_notes']);
  $data['adjustment_states']=$input['_adjustment_states']??[];
  if ($vat['sales_evidence_missing']>0 || $vat['invalid_lines']>0 || $vat['unreconciled_transactions']>0 || $data['unitemized_transactions']>0) {
   $data['net_sales_receipts']=null;$data['gross_income']=null;
   $data['vat']['net_sales_revenue']=null;$data['vat']['output_vat']=null;
   foreach($data['vat']['quarters'] as &$q){$q['net_sales']=null;$q['output_vat']=null;}unset($q);
  }
  if ($vat['cost_evidence_missing']>0 || $vat['invalid_lines']>0 || $data['unitemized_transactions']>0) {
   $data['cost_of_sales']=null;$data['gross_income']=null;
   $data['vat']['cost_of_sales']=null;$data['vat']['input_vat']=null;
   foreach($data['vat']['quarters'] as &$q){$q['cost_of_sales']=null;}unset($q);
  }
  if ($data['gross_income']===null) $data['business_net_income']=null;
  if ($issues) {
   foreach(['taxable_net_income','income_tax_due','income_tax_rate','basic_tax_payment','total_amount_payable','graduated_tax'] as $field) $data[$field]=null;
  }
  foreach(['computations','computation'] as $section) {
   foreach(['net_sales'=>'net_sales_receipts','net_sales_receipts'=>'net_sales_receipts','cost_of_sales'=>'cost_of_sales','less_cost_of_sales'=>'cost_of_sales','gross_income'=>'gross_income','taxable_net_income'=>'taxable_net_income','tax_due'=>'income_tax_due','income_tax_due'=>'income_tax_due','income_tax_rate'=>'income_tax_rate','basic_tax_payment'=>'basic_tax_payment','total_amount_payable'=>'total_amount_payable'] as $field=>$source) {
    if(array_key_exists($field,$data[$section]))$data[$section][$field]=$data[$source];
   }
  }
  return $data;
 }
}
