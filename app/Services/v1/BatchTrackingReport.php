<?php

namespace App\Services\v1;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BatchTrackingReport
{
    public function rows(array $branchIds, Carbon $from, Carbon $to): Collection
    {
        return DB::table('inventories as i')
            ->join('batches as b', 'b.batch_id', '=', 'i.batch_id')
            ->join('medicines as m', 'm.medicine_id', '=', 'i.medicine_id')
            ->join('branches as br', 'br.branch_id', '=', 'i.branch_id')
            ->whereIn('i.branch_id', $branchIds)
            ->whereBetween('b.received_date', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('b.received_date')
            ->orderBy('i.inventory_id')
            ->get([
                'i.inventory_id', 'b.supplier', 'b.batch_id', 'b.batch_number', 'm.medicine_name',
                'm.generic_name', 'br.branch_name', 'b.received_date', 'b.mfg_date',
                'b.expiry_date', 'b.location', 'b.status', 'i.stocks',
            ])
            ->map(function ($row) {
                $row->stocks = (int) $row->stocks;
                if (!in_array($row->status, ['disposed', 'pulled_out'], true)
                    && $row->expiry_date && $row->expiry_date <= now()->toDateString()) {
                    $row->status = 'expired';
                }
                $row->status ??= 'active';

                return $row;
            });
    }
}
