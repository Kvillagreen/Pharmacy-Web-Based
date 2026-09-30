<?php
namespace App\Services\v1;

final class SaleAmounts
{
    public static function calculate(array $items, string $discountType, float $manualDiscount = 0): array
    {
        $statutory = in_array(strtolower($discountType), ['senior', 'pwd', 'scpwd'], true);
        $gross = array_map(fn ($i) => (int) round((float) $i['price'] * 100) * (int) $i['quantity'], $items);
        $bases = array_map(fn ($i, $g) => $statutory && (int) ($i['is_vat_exempt'] ?? 0) !== 1 ? $g / 1.12 : $g, $items, $gross);
        $subtotal = array_sum($gross);
        $base = (int) round(array_sum($bases));
        $discount = $statutory ? (int) round($base * .20) : (int) round($manualDiscount * 100);
        if ($discount < 0 || $discount > $base) {
            throw new \RuntimeException('Discount cannot exceed the sale amount.', 422);
        }
        $total = $base - $discount;
        // Cumulative allocation keeps line cents equal to the header, including split batches.
        $running = 0; $allocated = 0; $lines = [];
        foreach ($items as $index => $item) {
            $running += $bases[$index];
            $next = array_sum($bases) > 0 ? (int) round($total * $running / array_sum($bases)) : 0;
            $net = $next - $allocated; $allocated = $next;
            $exempt = $statutory || (int) ($item['is_vat_exempt'] ?? 0) === 1;
            $vat = $exempt ? 0 : $net - (int) round($net / 1.12);
            $lines[] = ['net_amount' => $net / 100, 'output_vat' => $vat / 100];
        }
        return ['sub_total' => $subtotal / 100, 'discount' => $discount / 100,
            'total_amount' => $total / 100, 'lines' => $lines];
    }
}
