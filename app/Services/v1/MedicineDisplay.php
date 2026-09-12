<?php

namespace App\Services\v1;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class MedicineDisplay
{
    public static function paginate($query, Request $request, int $perPage): LengthAwarePaginator
    {
        $groups = $query->get()->groupBy(function ($row) {
            return json_encode([
                (int) $row->branch_id,
                trim((string) $row->medicine_name),
                trim((string) $row->generic_name),
                (float) $row->price,
                trim((string) $row->type),
                trim((string) $row->dosage),
                trim((string) $row->unit),
            ]);
        })->map(function ($rows) {
            $item = $rows->first()->toArray();
            $item['members'] = $rows->map(fn ($row) => $row->toArray())->values()->all();
            $item['stocks'] = (int) $rows->sum('stocks');
            $item['expiry_dates'] = $rows->pluck('expiry_date')->filter()->unique()->sort()->values()->all();
            $item['needs_protection'] = $rows->contains(fn ($row) => (bool) $row->needs_protection);
            $item['is_dangerous'] = $rows->contains(fn ($row) => (bool) $row->is_dangerous);
            return $item;
        })->values();

        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min($perPage, 500));
        return new LengthAwarePaginator($groups->forPage($page, $perPage)->values(), $groups->count(), $perPage, $page);
    }
}
