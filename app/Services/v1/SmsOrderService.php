<?php

namespace App\Services\v1;

use App\Models\v1\Batch;
use App\Models\v1\Inventory;
use App\Models\v1\Medicine;
use App\Models\v1\SmsOrder;
use App\Models\v1\Transaction;
use App\Models\v1\TransactionItem;
use App\Models\v1\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SmsOrderService
{
    public function createFromInbound(int $branchId, string $customerNumber, string $message, ?string $providerMessageId = null): SmsOrder
    {
        if ($providerMessageId !== null && ($existing = SmsOrder::where('provider_message_id', $providerMessageId)->first())) {
            return $existing->load('items.medicine');
        }

        $requested = $this->parse($message);

        return DB::transaction(function () use ($branchId, $customerNumber, $message, $providerMessageId, $requested) {
            $medicines = Medicine::whereIn('medicine_id', array_keys($requested))->get()->keyBy('medicine_id');
            $items = [];
            $total = 0.0;

            foreach ($requested as $medicineId => $quantity) {
                $medicine = $medicines->get($medicineId);
                if (!$medicine) {
                    throw ValidationException::withMessages(['message_body' => "Medicine {$medicineId} was not found."]);
                }

                $available = Inventory::where('branch_id', $branchId)
                    ->where('medicine_id', $medicineId)
                    ->where('stocks', '>', 0)
                    ->whereHas('batch', fn ($query) => $query->where('status', '!=', 'archived')->whereDate('expiry_date', '>=', today()))
                    ->sum('stocks');
                if ($available < $quantity) {
                    throw ValidationException::withMessages(['message_body' => "Insufficient stock for {$medicine->medicine_name}; requested {$quantity}, available {$available}."]);
                }

                $unitPrice = (float) $medicine->price;
                $lineTotal = round($unitPrice * $quantity, 2);
                $total += $lineTotal;
                $items[] = compact('medicineId', 'quantity', 'unitPrice', 'lineTotal');
            }

            $order = SmsOrder::create([
                'branch_id' => $branchId,
                'customer_number' => trim($customerNumber),
                'message_body' => trim($message),
                'provider_message_id' => $providerMessageId,
                'status' => 'pending',
                'total_price' => round($total, 2),
            ]);

            foreach ($items as $item) {
                $order->items()->create([
                    'medicine_id' => $item['medicineId'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unitPrice'],
                    'line_total' => $item['lineTotal'],
                ]);
            }

            return $order->load('items.medicine');
        });
    }

    public function processWalkIn(SmsOrder $order, User $user, string $paymentMethod = 'Cash', ?float $amountTendered = null): Transaction
    {
        return DB::transaction(function () use ($order, $user, $paymentMethod, $amountTendered) {
            $order = SmsOrder::whereKey($order->getKey())->lockForUpdate()->with('items')->firstOrFail();
            if ($order->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'Only pending SMS orders can be processed.']);
            }

            $total = (float) $order->total_price;
            $tendered = $amountTendered ?? $total;
            if ($paymentMethod === 'Cash' && $tendered < $total) {
                throw ValidationException::withMessages(['amount_tendered' => 'Amount tendered must cover the order total.']);
            }

            $transaction = Transaction::create([
                'user_id' => $user->user_id,
                'branch_id' => $order->branch_id,
                'transaction_type' => 'Walk-in',
                'sub_total' => $total,
                'discount' => 0,
                'vat_amount' => 0,
                'total_amount' => $total,
                'payment_method' => $paymentMethod,
                'used_amount' => $tendered,
                'change' => max(0, $tendered - $total),
                'status' => 'completed',
                'customer_contact_number' => $order->customer_number,
            ]);

            foreach ($order->items as $orderItem) {
                $remaining = (int) $orderItem->quantity;
                $inventories = Inventory::query()
                    ->join('batches', 'batches.batch_id', '=', 'inventories.batch_id')
                    ->where('inventories.branch_id', $order->branch_id)
                    ->where('inventories.medicine_id', $orderItem->medicine_id)
                    ->where('inventories.stocks', '>', 0)
                    ->where('batches.status', '!=', 'archived')
                    ->whereDate('batches.expiry_date', '>=', today())
                    ->orderBy('batches.expiry_date')->orderBy('batches.received_date')->orderBy('inventories.inventory_id')
                    ->lockForUpdate()
                    ->get(['inventories.*', 'batches.batch_number', 'batches.expiry_date', 'batches.mfg_date']);

                if ($inventories->sum('stocks') < $remaining) {
                    throw ValidationException::withMessages(['stock' => "Insufficient stock for medicine {$orderItem->medicine_id}."]);
                }

                foreach ($inventories as $inventory) {
                    if ($remaining === 0) break;
                    $used = min((int) $inventory->stocks, $remaining);
                    Inventory::whereKey($inventory->inventory_id)->decrement('stocks', $used);
                    TransactionItem::create([
                        'transaction_id' => $transaction->transaction_id,
                        'medicine_id' => $orderItem->medicine_id,
                        'batch_id' => $inventory->batch_id,
                        'batch_number' => $inventory->batch_number,
                        'expiry_date' => $inventory->expiry_date,
                        'mfg_date' => $inventory->mfg_date,
                        'quantity' => $used,
                        'price' => $orderItem->unit_price,
                    ]);
                    $remaining -= $used;
                }

                Medicine::whereKey($orderItem->medicine_id)->update([
                    'stocks' => Inventory::where('medicine_id', $orderItem->medicine_id)->sum('stocks'),
                ]);
            }

            $order->update(['status' => 'completed']);
            return $transaction->load('items.batch');
        });
    }

    /** @return array<int,int> */
    private function parse(string $message): array
    {
        $tokens = preg_split('/[\s,]+/', trim($message)) ?: [];
        if (strtoupper((string) array_shift($tokens)) !== 'MED' || count($tokens) < 2 || count($tokens) % 2 !== 0) {
            throw ValidationException::withMessages(['message_body' => 'Use MED <medicine id> <quantity>, for example: MED 10 2 11 1.']);
        }

        $requested = [];
        for ($i = 0; $i < count($tokens); $i += 2) {
            if (!ctype_digit($tokens[$i]) || !ctype_digit($tokens[$i + 1]) || (int) $tokens[$i + 1] < 1) {
                throw ValidationException::withMessages(['message_body' => 'Medicine IDs and quantities must be positive whole numbers.']);
            }
            $id = (int) $tokens[$i];
            $requested[$id] = ($requested[$id] ?? 0) + (int) $tokens[$i + 1];
        }
        return $requested;
    }
}
