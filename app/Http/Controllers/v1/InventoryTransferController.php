<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\v1\Batch;
use App\Models\v1\Branch;
use App\Models\v1\Inventory;
use App\Models\v1\InventoryTransfer;
use App\Models\v1\Medicine;
use App\Models\v1\User;
use App\Models\v1\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryTransferController extends Controller
{
    public function index(Request $request)
    {
        $companyId = (int) $request->input('company_id', 0);
        $branchId = (int) $request->input('branch_id', 0);

        $transfers = InventoryTransfer::query()
            ->with([
                'medicine:medicine_id,medicine_name,generic_name',
                'batch:batch_id,expiry_date,received_date,mfg_date,location',
                'fromBranch:branch_id,branch_name',
                'toBranch:branch_id,branch_name',
                'requester:user_id,first_name,last_name',
                'resolver:user_id,first_name,last_name',
            ])
            ->when($branchId > 0, function ($query) use ($branchId) {
                $query->where(function ($nested) use ($branchId) {
                    $nested->where('from_branch_id', $branchId)
                        ->orWhere('to_branch_id', $branchId);
                });
            })
            ->when($branchId <= 0 && $companyId > 0, function ($query) use ($companyId) {
                $branchIds = Branch::query()
                    ->where('company_id', $companyId)
                    ->pluck('branch_id');

                $query->where(function ($nested) use ($branchIds) {
                    $nested->whereIn('from_branch_id', $branchIds)
                        ->orWhereIn('to_branch_id', $branchIds);
                });
            })
            ->latest('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $transfers,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'inventory_id' => ['required', 'integer', 'exists:inventories,inventory_id'],
            'to_branch_id' => ['required', 'integer', 'exists:branches,branch_id'],
            'requested_by' => ['required', 'integer', 'exists:users,user_id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'confirmed' => ['required', 'boolean'],
        ]);

        if (!$validated['confirmed']) {
            return response()->json([
                'success' => false,
                'message' => 'Transfer must be confirmed before submission.',
            ], 422);
        }

        $inventory = Inventory::query()
            ->with(['medicine', 'batch', 'branch'])
            ->findOrFail($validated['inventory_id']);

        if ((int) $inventory->branch_id === (int) $validated['to_branch_id']) {
            return response()->json([
                'success' => false,
                'message' => 'Please choose a different destination branch.',
            ], 422);
        }

        if ((int) $inventory->stocks <= 2) {
            return response()->json([
                'success' => false,
                'message' => 'Medicines with 2 or fewer stocks cannot be transferred.',
            ], 422);
        }

        if ((int) $inventory->stocks < (int) $validated['quantity']) {
            return response()->json([
                'success' => false,
                'message' => 'Not enough stock available for transfer.',
            ], 422);
        }

        $transfer = InventoryTransfer::create([
            'medicine_id' => $inventory->medicine_id,
            'batch_id' => $inventory->batch_id,
            'from_branch_id' => $inventory->branch_id,
            'to_branch_id' => $validated['to_branch_id'],
            'requested_by' => $validated['requested_by'],
            'quantity' => $validated['quantity'],
            'notes' => $validated['notes'] ?? null,
            'confirmed_at' => now(),
            'status' => 'pending',
        ]);

        $requester = User::query()->find($validated['requested_by']);
        $destinationUsers = User::query()
            ->where('branch_id', $validated['to_branch_id'])
            ->where('status', 'approved')
            ->get();

        foreach ($destinationUsers as $user) {
            UserNotification::create([
                'user_id' => $user->user_id,
                'branch_id' => $validated['to_branch_id'],
                'type' => 'transfer_request',
                'title' => 'Incoming medicine transfer',
                'message' => sprintf(
                    '%s request to transfer %d unit(s) of %s from %s.',
                    trim(($requester?->first_name ?? '') . ' ' . ($requester?->last_name ?? '')),
                    $transfer->quantity,
                    $inventory->medicine?->medicine_name ?? 'a medicine',
                    $inventory->branch?->branch_name ?? 'another branch'
                ),
                'meta' => [
                    'inventory_transfer_id' => $transfer->inventory_transfer_id,
                    'quantity' => $transfer->quantity,
                    'medicine_name' => $inventory->medicine?->medicine_name,
                    'from_branch_name' => $inventory->branch?->branch_name,
                    'to_branch_id' => $validated['to_branch_id'],
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Transfer request sent successfully.',
            'data' => $transfer->load(['medicine', 'fromBranch', 'toBranch']),
        ], 201);
    }

    public function accept(Request $request, string $id)
    {
        return $this->resolveTransfer($request, (int) $id, 'accepted');
    }

    public function decline(Request $request, string $id)
    {
        return $this->resolveTransfer($request, (int) $id, 'declined');
    }

    private function resolveTransfer(Request $request, int $transferId, string $status)
    {
        $validated = $request->validate([
            'resolved_by' => ['required', 'integer', 'exists:users,user_id'],
            'notification_id' => ['nullable', 'integer', 'exists:user_notifications,user_notification_id'],
        ]);

        DB::beginTransaction();

        try {
            $transfer = InventoryTransfer::query()
                ->with(['medicine', 'batch', 'fromBranch', 'toBranch', 'requester'])
                ->lockForUpdate()
                ->findOrFail($transferId);

            if ($transfer->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'This transfer has already been resolved.',
                ], 422);
            }

            $fromInventory = Inventory::query()
                ->where('branch_id', $transfer->from_branch_id)
                ->where('medicine_id', $transfer->medicine_id)
                ->where('batch_id', $transfer->batch_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($status === 'accepted') {
                if ((int) $fromInventory->stocks < (int) $transfer->quantity) {
                    return response()->json([
                        'success' => false,
                        'message' => 'The source branch no longer has enough stock to complete this transfer.',
                    ], 422);
                }

                $fromInventory->decrement('stocks', $transfer->quantity);
                $destinationInventory = $this->resolveDestinationInventory($transfer);
                $destinationInventory->increment('stocks', $transfer->quantity);

                $this->syncMedicineStocks($fromInventory->medicine_id);
                $this->syncMedicineStocks($destinationInventory->medicine_id);

                $transfer->medicine_id = $destinationInventory->medicine_id;
                $transfer->batch_id = $destinationInventory->batch_id;
            }

            $transfer->update([
                'medicine_id' => $transfer->medicine_id,
                'batch_id' => $transfer->batch_id,
                'status' => $status,
                'resolved_by' => $validated['resolved_by'],
                'resolved_at' => now(),
            ]);

            if (!empty($validated['notification_id'])) {
                UserNotification::query()
                    ->where('user_notification_id', $validated['notification_id'])
                    ->update(['read_at' => now()]);
            }

            $this->notifyRequester($transfer, $status);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $status === 'accepted'
                    ? 'Transfer accepted and inventory updated.'
                    : 'Transfer declined. Stock remains in the original branch inventory.',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to resolve transfer.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function notifyRequester(InventoryTransfer $transfer, string $status): void
    {
        UserNotification::create([
            'user_id' => $transfer->requested_by,
            'branch_id' => $transfer->from_branch_id,
            'type' => 'transfer_' . $status,
            'title' => $status === 'accepted' ? 'Transfer accepted' : 'Transfer declined',
            'message' => sprintf(
                '%s %s the transfer of %d unit(s) of %s.',
                $transfer->toBranch?->branch_name ?? 'Destination branch',
                $status,
                $transfer->quantity,
                $transfer->medicine?->medicine_name ?? 'the medicine'
            ),
            'meta' => [
                'inventory_transfer_id' => $transfer->inventory_transfer_id,
                'status' => $status,
                'quantity' => $transfer->quantity,
                'medicine_name' => $transfer->medicine?->medicine_name,
            ],
        ]);
    }

    private function syncMedicineStocks(int $medicineId): void
    {
        $totalStocks = (int) Inventory::query()
            ->where('medicine_id', $medicineId)
            ->sum('stocks');

        Medicine::query()
            ->where('medicine_id', $medicineId)
            ->update(['stocks' => $totalStocks]);
    }

    private function resolveDestinationInventory(InventoryTransfer $transfer): Inventory
    {
        $matchingInventory = Inventory::query()
            ->with(['medicine', 'batch'])
            ->where('branch_id', $transfer->to_branch_id)
            ->lockForUpdate()
            ->get()
            ->first(function (Inventory $inventory) use ($transfer) {
                return $this->medicineMatches($inventory->medicine, $transfer->medicine)
                    && $this->batchMatches($inventory->batch, $transfer->batch);
            });

        if ($matchingInventory) {
            return $matchingInventory;
        }

        $newMedicine = Medicine::create([
            'medicine_name' => $transfer->medicine?->medicine_name,
            'generic_name' => $transfer->medicine?->generic_name,
            'category' => $transfer->medicine?->category,
            'stocks' => 0,
            'unit' => $transfer->medicine?->unit,
            'dosage' => $transfer->medicine?->dosage,
            'price' => $transfer->medicine?->price,
            'type' => $transfer->medicine?->type,
            'reorder_level' => $transfer->medicine?->reorder_level,
            'is_dangerous' => (bool) $transfer->medicine?->is_dangerous,
            'needs_protection' => (bool) $transfer->medicine?->needs_protection,
        ]);

        $newBatch = Batch::create([
            'expiry_date' => $transfer->batch?->expiry_date,
            'received_date' => $transfer->batch?->received_date,
            'mfg_date' => $transfer->batch?->mfg_date,
            'location' => $transfer->batch?->location,
            'status' => $transfer->batch?->status ?? 'active',
        ]);

        return Inventory::create([
            'branch_id' => $transfer->to_branch_id,
            'medicine_id' => $newMedicine->medicine_id,
            'batch_id' => $newBatch->batch_id,
            'stocks' => 0,
        ]);
    }

    private function medicineMatches(?Medicine $existingMedicine, ?Medicine $sourceMedicine): bool
    {
        if (!$existingMedicine || !$sourceMedicine) {
            return false;
        }

        return $existingMedicine->medicine_name === $sourceMedicine->medicine_name
            && $existingMedicine->generic_name === $sourceMedicine->generic_name
            && $existingMedicine->category === $sourceMedicine->category
            && (string) $existingMedicine->unit === (string) $sourceMedicine->unit
            && (string) $existingMedicine->dosage === (string) $sourceMedicine->dosage
            && (string) $existingMedicine->price === (string) $sourceMedicine->price
            && (string) $existingMedicine->type === (string) $sourceMedicine->type
            && (int) $existingMedicine->reorder_level === (int) $sourceMedicine->reorder_level
            && (bool) $existingMedicine->is_dangerous === (bool) $sourceMedicine->is_dangerous
            && (bool) $existingMedicine->needs_protection === (bool) $sourceMedicine->needs_protection;
    }

    private function batchMatches(?Batch $existingBatch, ?Batch $sourceBatch): bool
    {
        if (!$existingBatch || !$sourceBatch) {
            return false;
        }

        return (string) $existingBatch->expiry_date === (string) $sourceBatch->expiry_date
            && (string) $existingBatch->received_date === (string) $sourceBatch->received_date;
    }
}
