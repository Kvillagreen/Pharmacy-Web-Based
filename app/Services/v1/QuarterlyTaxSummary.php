<?php

namespace App\Services\v1;

/** Computation worksheet matching the supplied January 2018 Form 1701Q. */
class QuarterlyTaxSummary
{
    public function calculate(float $netSales, float $cost, array $input): array
    {
        $rows = [];
        // The form requires whole pesos, with 50 centavos rounded up.
        $add = function (string $item, string $label, string $formula, float $value, string $section) use (&$rows): float {
            $value = round($value, 0, PHP_ROUND_HALF_UP);
            $rows[] = compact('item', 'label', 'formula', 'value', 'section');
            return $value;
        };
        $amount = fn (string $key): float => round((float) ($input[$key] ?? 0), 0, PHP_ROUND_HALF_UP);
        $eight = ($input['tax_method'] ?? 'graduated_itemized') === 'eight_percent';
        $osd = ($input['tax_method'] ?? '') === 'graduated_osd';
        if ($eight) {
            $section = 'Schedule II - 8% income tax';
            $sales = $add('47', 'Net sales / receipts', 'Gross sales less discounts and VAT', $netSales, $section);
            $other = $add('48', 'Non-operating income', 'Entered for this quarter', $amount('other_income'), $section);
            $current = $add('49', 'Total income this quarter', '47 + 48', $sales + $other, $section);
            $previous = $add('50', 'Previous quarters cumulative income', 'Item 51 of previous quarter; entered', $amount('previous_income'), $section);
            $cumulative = $add('51', 'Cumulative income', '49 + 50', $current + $previous, $section);
            $reduction = $add('52', 'Allowable reduction', ($input['income_type'] ?? 'business') === 'mixed' ? 'Mixed income: no reduction' : 'Purely self-employed: PHP 250,000', ($input['income_type'] ?? 'business') === 'mixed' ? 0 : 250000, $section);
            $taxable = $add('53', 'Taxable income / (loss) to date', '51 - 52', $cumulative - $reduction, $section);
            $due = $add('54', 'Tax due', 'max(53, 0) x 8%', max(0, $taxable) * 0.08, $section);
        } else {
            $section = 'Schedule I - Graduated income tax';
            $sales = $add('36', 'Net sales / receipts', 'Gross sales less discounts and VAT', $netSales, $section);
            $cost = $add('37', 'Cost of sales', $osd ? 'Not deductible separately with OSD' : 'Sum of quantity x recorded unit cost', $osd ? 0 : $cost, $section);
            $gross = $add('38', 'Gross income / (loss)', '36 - 37', $sales - $cost, $section);
            $itemized = $add('39', 'Allowable itemized deductions', $osd ? 'Not applicable with OSD' : 'Entered expenses for this quarter', $osd ? 0 : $amount('itemized_deductions'), $section);
            $standard = $add('40', 'Optional standard deduction', $osd ? '36 x 40%' : 'Not applicable with itemized deductions', $osd ? max(0, $sales) * 0.4 : 0, $section);
            $current = $add('41', 'Net income / (loss) this quarter', $osd ? '38 - 40' : '38 - 39', $gross - $itemized - $standard, $section);
            $previous = $add('42', 'Previous quarters taxable income / (loss)', 'Entered cumulative taxable income', $amount('previous_income'), $section);
            $other = $add('43', 'Non-operating income', 'Entered for this quarter', $amount('other_income'), $section);
            $gpp = $add('44', 'Share in GPP income', 'Entered for this quarter', $amount('gpp_income'), $section);
            $taxable = $add('45', 'Total taxable income / (loss) to date', '41 + 42 + 43 + 44', $current + $previous + $other + $gpp, $section);
            [$tax, $formula] = $this->graduated(max(0, $taxable), (int) $input['year']);
            $due = $add('46', 'Tax due', $formula, $tax, $section);
        }
        $credits = 0;
        $creditFields = ['prior_year_credit' => 'Prior year excess credits', 'previous_payments' => 'Previous quarters tax payments', 'previous_withholding' => 'Previous quarters creditable withholding', 'current_withholding' => 'Current quarter withholding (2307)', 'amended_payment' => 'Tax paid on previously filed return', 'foreign_credit' => 'Foreign tax credits', 'other_credits' => 'Other tax credits / payments'];
        $item = 55;
        foreach ($creditFields as $key => $label) {
            $credits += $add((string) $item++, $label, 'Entered amount', $amount($key), 'Schedule III - Tax credits / payments');
        }
        $credits = $add('62', 'Total tax credits / payments', 'Sum of 55 through 61', $credits, 'Schedule III - Tax credits / payments');
        $payable = $add('63', 'Tax payable / (overpayment)', ($eight ? '54' : '46').' - 62', $due - $credits, 'Schedule III - Tax credits / payments');
        $penalties = 0;
        $item = 64;
        foreach (['surcharge', 'interest', 'compromise'] as $key) {
            $penalties += $add((string) $item++, ucfirst($key), 'Entered assessed amount', $amount($key), 'Schedule IV - Penalties');
        }
        $penalties = $add('67', 'Total penalties', '64 + 65 + 66', $penalties, 'Schedule IV - Penalties');
        $total = $add('68', 'Total amount payable / (overpayment)', '63 + 67', $payable + $penalties, 'Schedule IV - Penalties');
        foreach ([['26', 'Tax due', '46 or 54', $due], ['27', 'Tax credits / payments', '62', $credits], ['28', 'Tax payable / (overpayment)', '26 - 27', $payable], ['29', 'Total penalties', '67', $penalties], ['30', 'Total amount payable / (overpayment)', '28 + 29', $total]] as [$item, $label, $formula, $value]) {
            $add($item, $label, $formula, $value, 'Part III - Total tax payable');
        }
        return ['computation_rows' => $rows, 'taxable_net_income' => $taxable, 'income_tax_due' => $due, 'tax_credits' => $credits, 'basic_tax_payment' => $payable, 'total_penalties' => $penalties, 'total_amount_payable' => $total];
    }

    public function graduated(float $income, int $year): array
    {
        $bands = $year >= 2023
            ? [[8000000, 2202500, .35], [2000000, 402500, .30], [800000, 102500, .25], [400000, 22500, .20], [250000, 0, .15]]
            : [[8000000, 2410000, .35], [2000000, 490000, .32], [800000, 130000, .30], [400000, 30000, .25], [250000, 0, .20]];
        foreach ($bands as [$threshold, $base, $rate]) {
            if ($income > $threshold) {
                return [$base + ($income - $threshold) * $rate, 'PHP '.number_format($base).' + (taxable income - PHP '.number_format($threshold).') x '.($rate * 100).'%'];
            }
        }
        return [0, 'Taxable income at or below PHP 250,000: 0'];
    }
}
