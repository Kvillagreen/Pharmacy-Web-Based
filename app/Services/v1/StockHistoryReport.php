<?php

namespace App\Services\v1;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class StockHistoryReport
{
    public function rows(array $scopeBranchIds, Carbon $rangeStart, Carbon $rangeEnd)
    {
        return DB::table('batch_histories')
            ->join('batches', 'batch_histories.batch_id', '=', 'batches.batch_id')
            ->join('inventories', 'batches.batch_id', '=', 'inventories.batch_id')
            ->join('medicines', 'inventories.medicine_id', '=', 'medicines.medicine_id')
            ->join('branches', 'inventories.branch_id', '=', 'branches.branch_id')
            ->leftJoin('users', 'batch_histories.user_id', '=', 'users.user_id')
            ->whereIn('inventories.branch_id', $scopeBranchIds)
            ->whereBetween('batch_histories.created_at', [$rangeStart, $rangeEnd])
            ->selectRaw('
                batch_histories.batch_history_id,
                batch_histories.action,
                batch_histories.notes,
                batch_histories.created_at,
                batches.batch_number,
                medicines.medicine_name,
                branches.branch_name,
                users.first_name,
                users.last_name
            ')
            ->orderByDesc('batch_histories.created_at')
            ->get();
    }
}
