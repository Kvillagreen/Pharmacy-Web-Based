<?php
namespace App\Services\v1;
use App\Models\v1\Inventory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Inventory rows are authoritative; medicine totals are only a derived compatibility cache. */
class StockMovementService {
    public function set(Inventory $row, int $quantity, string $action, ?string $reference = null): Inventory {
        return DB::transaction(function () use ($row, $quantity, $action, $reference) {
            if ($quantity < 0) throw ValidationException::withMessages(['stocks' => 'Stock cannot be negative.']);
            $locked = Inventory::whereKey($row->getKey())->lockForUpdate()->firstOrFail();
            $before = (int) $locked->stocks;
            $locked->update(['stocks' => $quantity]);
            if ($before !== $quantity) DB::table('stock_movements')->insert([
                'inventory_id' => $locked->inventory_id, 'medicine_id' => $locked->medicine_id,
                'branch_id' => $locked->branch_id, 'actor_id' => auth()->id(), 'action' => $action,
                'quantity_change' => $quantity - $before, 'stock_before' => $before, 'stock_after' => $quantity,
                'reference' => $reference, 'created_at' => now(),
            ]);
            $this->sync((int) $locked->medicine_id);
            $row->stocks = $quantity;
            return $locked;
        });
    }
    public function change(Inventory $row, int $delta, string $action, ?string $reference = null): Inventory {
        return DB::transaction(function () use ($row, $delta, $action, $reference) {
            $locked = Inventory::whereKey($row->getKey())->lockForUpdate()->firstOrFail();
            return $this->set($locked, (int) $locked->stocks + $delta, $action, $reference);
        });
    }
    public function clearBatch(int $batchId, string $action): void {
        foreach (Inventory::where('batch_id', $batchId)->orderBy('inventory_id')->lockForUpdate()->get() as $row) {
            $this->set($row, 0, $action, 'Batch #'.$batchId);
        }
    }
    public function transfer(Inventory $source, Inventory $destination, int $quantity, string $reference): void {
        if ($quantity <= 0 || $source->medicine_id !== $destination->medicine_id || $source->batch_id !== $destination->batch_id || $source->getKey() === $destination->getKey()) {
            throw ValidationException::withMessages(['quantity' => 'Transfer requires a positive quantity and matching product/batch in different inventory rows.']);
        }
        DB::transaction(function () use ($source, $destination, $quantity, $reference) {
            Inventory::whereIn('inventory_id', [$source->getKey(),$destination->getKey()])->orderBy('inventory_id')->lockForUpdate()->get();
            $this->change($source, -$quantity, 'transfer_out', $reference);
            $this->change($destination, $quantity, 'transfer_in', $reference);
        }, 3);
    }
    public function sync(int $medicineId): void {
        DB::table('medicines')->where('medicine_id', $medicineId)->update([
            'stocks' => (int) Inventory::where('medicine_id', $medicineId)->orderBy('inventory_id')->lockForUpdate()->get()->sum('stocks'),
        ]);
    }
}
