<?php
$items = App\Models\v1\Inventory::join("medicines", "medicines.medicine_id", "=", "inventories.medicine_id")
    ->leftJoin("batches", "inventories.batch_id", "=", "batches.batch_id")
    ->where("inventories.branch_id", 1)
    ->where("medicines.stocks", ">", 0)
    ->get(["inventories.inventory_id", "medicines.medicine_name", "batches.batch_number", "batches.expiry_date"]);
echo json_encode($items);
