<?php
$results = App\Models\v1\Medicine::query()
    ->join("inventories", "medicines.medicine_id", "=", "inventories.medicine_id")
    ->leftJoin("batches", "inventories.batch_id", "=", "batches.batch_id")
    ->join("branches", "inventories.branch_id", "=", "branches.branch_id")
    ->where("branches.status", "active")
    ->where(function ($query) {
        $query->whereNull("batches.expiry_date")
            ->orWhereDate("batches.expiry_date", ">", now()->toDateString());
    })
    ->selectRaw('inventories.branch_id, count(*) as count')
    ->groupBy('inventories.branch_id')
    ->get();
echo json_encode($results);
