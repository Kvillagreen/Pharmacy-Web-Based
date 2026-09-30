<?php
echo App\Models\v1\Inventory::join("medicines", "medicines.medicine_id", "=", "inventories.medicine_id")
    ->leftJoin("batches", "inventories.batch_id", "=", "batches.batch_id")
    ->leftJoin("branches", "inventories.branch_id", "=", "branches.branch_id")
    ->where("inventories.branch_id", 1)
    ->where("branches.status", "active")
    ->where(function ($query) { 
        $query->whereNull("batches.expiry_date")
            ->orWhereDate("batches.expiry_date", ">", now()->toDateString()); 
    })
    ->count();
