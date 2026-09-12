<?php

namespace App\Services\v1;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class MedicineDisplay
{
    private static function normalizeText($value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value)));
    }

    private static function normalizeDosage($value): string
    {
        $text = self::normalizeText($value);
        return is_numeric($text) ? (string) (float) $text : $text;
    }

    public static function paginate($query, Request $request, int $perPage): LengthAwarePaginator
    {
        $groups = $query->get()->groupBy(function ($row) {
            return json_encode([
                (int) $row->branch_id,
                self::normalizeText($row->medicine_name),
                self::normalizeText($row->generic_name),
                (float) $row->price,
                self::normalizeText($row->type),
                self::normalizeDosage($row->dosage),
                self::normalizeText($row->unit),
            ]);
        })->map(function ($rows) {
            $item = $rows->first()->toArray();
            $item['members'] = $rows->map(fn ($row) => $row->toArray())->values()->all();
            $item['stocks'] = (int) $rows->sum('stocks');
            $item['expiry_dates'] = $rows->pluck('expiry_date')->filter()->unique()->sort()->values()->all();
            $item['expiry_date'] = $item['expiry_dates'][0] ?? null;
            $item['needs_protection'] = $rows->contains(fn ($row) => (bool) $row->needs_protection);
            $item['is_dangerous'] = $rows->contains(fn ($row) => (bool) $row->is_dangerous);
            return $item;
        })->values();

        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min($perPage, 500));
        return new LengthAwarePaginator($groups->forPage($page, $perPage)->values(), $groups->count(), $perPage, $page);
    }
}
