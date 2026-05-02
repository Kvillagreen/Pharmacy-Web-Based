<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\v1\MethodMedicineRequest;
use App\Models\v1\Batch;
use App\Models\v1\Inventory;
use App\Models\v1\InventoryTransfer;
use App\Models\v1\Medicine;
use App\Models\v1\TransactionItem;
use App\Services\v1\MedicineQuery;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MedicineController extends Controller
{
    public function index(Request $request)
    {
        $site = strtolower($request->header('X-Page-Context', ''));
        $companyId = (int) $request->input('company_id', 0);
        $branchId = (int) $request->input('branch_id', 0);
        $perPage = (int) $request->input('per_page', 10);
        $isExport = filter_var($request->input('export', false), FILTER_VALIDATE_BOOLEAN);
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');
        $today = Carbon::today();

        $query = Medicine::query()
            ->join('inventories', 'medicines.medicine_id', '=', 'inventories.medicine_id')
            ->leftJoin('batches', 'inventories.batch_id', '=', 'batches.batch_id')
            ->leftJoin('branches', 'inventories.branch_id', '=', 'branches.branch_id')
            ->select([
                'inventories.inventory_id',
                'inventories.branch_id',
                'branches.company_id',
                'inventories.medicine_id',
                'medicines.medicine_name',
                'medicines.generic_name',
                'medicines.category',
                'medicines.price',
                'medicines.reorder_level',
                'inventories.stocks',
                'medicines.dosage',
                'medicines.unit',
                'medicines.type',
                'medicines.is_dangerous',
                'medicines.needs_protection',
                'batches.batch_id',
                'batches.expiry_date',
                'batches.received_date',
                'batches.status as batch_status',
                'batches.mfg_date',
                'batches.location',
                'inventories.created_at',
                'inventories.updated_at',
            ])
         ->where('branches.status', 'active')
        ->where('batches.expiry_date', '>', now())
        ->orderBy('medicines.medicine_name')
        ->orderBy('batches.expiry_date', 'asc')
        ->orderBy('batches.received_date', 'asc')
        ->orderBy('inventories.inventory_id', 'asc');

        if ($branchId > 0) {
            $query->where('inventories.branch_id', $branchId);
        } elseif ($companyId > 0) {
            $query->where('branches.company_id', $companyId);
        }

        if (!empty($fromDate)) {
            $query->whereDate('batches.created_at', '>=', $fromDate);
        }

        if (!empty($toDate)) {
            $query->whereDate('batches.created_at', '<=', $toDate);
        }

        if ($request->hasAny(['search', 'sort', 'filter']) || $branchId > 0 || $companyId > 0) {
            $query = (new MedicineQuery())->apply($request, $query);
        }

        if ($isExport) {
            return response()->json([
                'data' => $query->get(),
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'scope' => $branchId > 0 ? 'branch' : ($companyId > 0 ? 'company' : 'all'),
                'date_field' => 'received_date',
                'from_date' => $fromDate,
                'to_date' => $toDate,
            ]);
        }

        $paginated = $query->paginate($perPage);

        $inventorySummary = Inventory::query()
            ->join('branches', 'branches.branch_id', '=', 'inventories.branch_id')
            ->join('medicines', 'medicines.medicine_id', '=', 'inventories.medicine_id')
            ->where('branches.status', 'active')
            ->when($branchId > 0, fn ($q) => $q->where('inventories.branch_id', $branchId))
            ->when($branchId <= 0 && $companyId > 0, fn ($q) => $q->where('branches.company_id', $companyId))
            ->selectRaw(
                'COUNT(*) as total_items,
                 COALESCE(SUM(medicines.price * inventories.stocks), 0) as total_amount,
                 SUM(CASE WHEN inventories.stocks < medicines.reorder_level THEN 1 ELSE 0 END) as low_stock_count,
                 SUM(CASE WHEN inventories.stocks = 0 THEN 1 ELSE 0 END) as out_of_stock_count'
            )
            ->first();

        $criticalUntil = $today->copy()->addDays(30)->toDateString();
        $warningStart = $today->copy()->addDays(31)->toDateString();
        $warningUntil = $today->copy()->addDays(90)->toDateString();
        $goodStart = $today->copy()->addDays(91)->toDateString();
        $todayDate = $today->toDateString();

        $batchSummary = Batch::query()
            ->join('inventories', 'inventories.batch_id', '=', 'batches.batch_id')
            ->join('branches', 'branches.branch_id', '=', 'inventories.branch_id')
            ->where('branches.status', 'active')
            ->when($branchId > 0, fn ($q) => $q->where('inventories.branch_id', $branchId))
            ->when($branchId <= 0 && $companyId > 0, fn ($q) => $q->where('branches.company_id', $companyId))
            ->selectRaw(
                'COUNT(DISTINCT CASE WHEN expiry_date >= ? AND expiry_date <= ? THEN batches.batch_id END) as critical_count,
                 COUNT(DISTINCT CASE WHEN expiry_date >= ? AND expiry_date <= ? THEN batches.batch_id END) as warning_count,
                 COUNT(DISTINCT CASE WHEN expiry_date >= ? THEN batches.batch_id END) as good_count,
                 COUNT(DISTINCT CASE WHEN expiry_date < ? THEN batches.batch_id END) as expired_count',
                [$todayDate, $criticalUntil, $warningStart, $warningUntil, $goodStart, $todayDate]
            )
            ->first();

        $response = [
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'scope' => $branchId > 0 ? 'branch' : ($companyId > 0 ? 'company' : 'all'),
            'site' => $site,
        ];

        if ($site === 'fefo') {
            $response = array_merge($response, [
                'critical' => (int) ($batchSummary->critical_count ?? 0),
                'good' => (int) ($batchSummary->good_count ?? 0),
                'expired' => (int) ($batchSummary->expired_count ?? 0),
                'low_stock' => (int) ($inventorySummary->low_stock_count ?? 0),
                'warning' => (int) ($batchSummary->warning_count ?? 0),
            ]);
        } elseif ($site === 'inventory') {
            $response = array_merge($response, [
                'total_items' => (int) ($inventorySummary->total_items ?? 0),
                'low_stock' => (int) ($inventorySummary->low_stock_count ?? 0),
                'out_of_stock' => (int) ($inventorySummary->out_of_stock_count ?? 0),
                'total_amount' => number_format((float) ($inventorySummary->total_amount ?? 0), 2),
            ]);
        }

        return response()->json($response);
    }

    public function store(MethodMedicineRequest $request)
    {
        DB::beginTransaction();

        try {
            $data = $request->validated();

            if (!empty($data['request_token']) && Cache::has($data['request_token'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Duplicate request detected!',
                ], 400);
            }

            if (!empty($data['request_token'])) {
                Cache::put($data['request_token'], true, 30);
            }

            $medicine = Medicine::create([
                'medicine_name' => $data['medicine_name'],
                'generic_name' => $data['generic_name'],
                'category' => $data['category'],
                'price' => $data['price'],
                'reorder_level' => $data['reorder_level'],
                'stocks' => $data['stocks'],
                'dosage' => $data['dosage'],
                'unit' => $data['unit'],
                'type' => $data['type'],
                'is_dangerous' => (bool) $data['is_dangerous'],
                'needs_protection' => (bool) $data['needs_protection'],
            ]);

            $batch = Batch::create([
                'expiry_date' => $data['expiry_date'],
                'received_date' => $data['received_date'],
                'mfg_date' => $data['mfg_date'],
                'location' => $data['location'],
                'status' => 'active',
            ]);

            $inventory = Inventory::create([
                'branch_id' => $data['branch_id'],
                'medicine_id' => $medicine->medicine_id,
                'batch_id' => $batch->batch_id,
                'stocks' => $data['stocks'],
            ]);

            $this->syncMedicineStocks($medicine->medicine_id);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Medicine, batch, and inventory saved',
                'data' => compact('medicine', 'batch', 'inventory'),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Transaction failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(string $id)
    {
    }

    public function edit(string $id)
    {
    }

    public function update(MethodMedicineRequest $request, $id)
    {
        DB::beginTransaction();

        try {
            $data = $request->validated();

            $medicine = Medicine::where('medicine_id', $id)->firstOrFail();
            $medicine->update([
                'medicine_name' => $data['medicine_name'],
                'generic_name' => $data['generic_name'],
                'category' => $data['category'],
                'price' => $data['price'],
                'reorder_level' => $data['reorder_level'],
                'dosage' => $data['dosage'],
                'unit' => $data['unit'],
                'type' => $data['type'],
                'needs_protection' => filter_var($data['needs_protection'], FILTER_VALIDATE_BOOLEAN),
                'is_dangerous' => filter_var($data['is_dangerous'], FILTER_VALIDATE_BOOLEAN),
            ]);

            $inventory = Inventory::where('medicine_id', $medicine->medicine_id)->firstOrFail();
            $inventory->update([
                'branch_id' => $data['branch_id'],
                'stocks' => $data['stocks'],
            ]);

            $batch = Batch::where('batch_id', $inventory->batch_id)->firstOrFail();
            $batch->update([
                'expiry_date' => $data['expiry_date'],
                'received_date' => $data['received_date'],
                'status' => 'active',
                'mfg_date' => $data['mfg_date'],
                'location' => $data['location'],
            ]);

            $this->syncMedicineStocks($medicine->medicine_id);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Medicine updated successfully',
                'data' => compact('medicine', 'batch', 'inventory'),
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Update failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($medicine_id)
    {
        DB::beginTransaction();

        try {
            $medicine = Medicine::where('medicine_id', $medicine_id)->firstOrFail();
            $inventoryBatchIds = Inventory::where('medicine_id', $medicine_id)->pluck('batch_id');

            Inventory::where('medicine_id', $medicine_id)->delete();
            Batch::whereIn('batch_id', $inventoryBatchIds)->delete();
            $medicine->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Medicine and linked inventory deleted successfully',
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Delete failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function mergeDuplicates(Request $request)
    {
        $validated = $request->validate([
            'company_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,branch_id'],
        ]);

        $companyId = (int) ($validated['company_id'] ?? 0);
        $branchId = (int) ($validated['branch_id'] ?? 0);

        DB::beginTransaction();

        try {
            $inventories = Inventory::query()
                ->with(['medicine', 'batch'])
                ->join('branches', 'branches.branch_id', '=', 'inventories.branch_id')
                ->where('branches.status', 'active')
                ->when($branchId > 0, fn ($query) => $query->where('inventories.branch_id', $branchId))
                ->when($branchId <= 0 && $companyId > 0, fn ($query) => $query->where('branches.company_id', $companyId))
                ->select('inventories.*')
                ->lockForUpdate()
                ->get();

            $groupedInventories = $inventories->groupBy(function (Inventory $inventory) {
                return implode('|', [
                    $inventory->branch_id,
                    $this->medicineMergeKey($inventory->medicine),
                    $this->batchMergeKey($inventory->batch),
                ]);
            });

            $mergedGroups = 0;
            $removedInventoryCount = 0;
            $removedMedicineCount = 0;
            $removedBatchCount = 0;
            $syncedMedicineIds = [];

            foreach ($groupedInventories as $group) {
                if ($group->count() <= 1) {
                    continue;
                }

                $primaryInventory = $group->sortBy('inventory_id')->first();
                $duplicateInventories = $group->sortBy('inventory_id')->slice(1)->values();
                $totalStocks = (int) $group->sum(fn (Inventory $inventory) => (int) $inventory->stocks);

                $primaryInventory->update([
                    'stocks' => $totalStocks,
                ]);

                $syncedMedicineIds[] = (int) $primaryInventory->medicine_id;
                $mergedGroups++;

                foreach ($duplicateInventories as $duplicateInventory) {
                    $duplicateMedicineId = (int) $duplicateInventory->medicine_id;
                    $duplicateBatchId = (int) $duplicateInventory->batch_id;

                    $duplicateInventory->delete();
                    $removedInventoryCount++;

                    if ($duplicateMedicineId && $duplicateMedicineId !== (int) $primaryInventory->medicine_id) {
                        InventoryTransfer::query()
                            ->where('medicine_id', $duplicateMedicineId)
                            ->update(['medicine_id' => $primaryInventory->medicine_id]);

                        $syncedMedicineIds[] = $duplicateMedicineId;

                        if (
                            !Inventory::query()->where('medicine_id', $duplicateMedicineId)->exists()
                            && !TransactionItem::query()->where('medicine_id', $duplicateMedicineId)->exists()
                        ) {
                            Medicine::query()->where('medicine_id', $duplicateMedicineId)->delete();
                            $removedMedicineCount++;
                        }
                    }

                    if ($duplicateBatchId && $duplicateBatchId !== (int) $primaryInventory->batch_id) {
                        InventoryTransfer::query()
                            ->where('batch_id', $duplicateBatchId)
                            ->update(['batch_id' => $primaryInventory->batch_id]);

                        if (!Inventory::query()->where('batch_id', $duplicateBatchId)->exists()) {
                            Batch::query()->where('batch_id', $duplicateBatchId)->delete();
                            $removedBatchCount++;
                        }
                    }
                }
            }

            foreach (array_unique($syncedMedicineIds) as $medicineId) {
                $this->syncMedicineStocks((int) $medicineId);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $mergedGroups > 0
                    ? 'Duplicate medicines were merged successfully.'
                    : 'No duplicate medicines were found for this inventory scope.',
                'data' => [
                    'merged_groups' => $mergedGroups,
                    'removed_inventories' => $removedInventoryCount,
                    'removed_medicines' => $removedMedicineCount,
                    'removed_batches' => $removedBatchCount,
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to merge duplicate medicines.',
                'error' => $e->getMessage(),
            ], 500);
        }
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

    private function medicineMergeKey(?Medicine $medicine): string
    {
        if (!$medicine) {
            return 'missing-medicine';
        }

        return implode('|', [
            $this->normalizeMergeValue($medicine->medicine_name),
            $this->normalizeMergeValue($medicine->generic_name),
            $this->normalizeMergeValue($medicine->category),
            $this->normalizeMergeValue($medicine->unit),
            $this->normalizeMergeValue($medicine->dosage),
            $this->normalizeMergeValue($medicine->price),
            $this->normalizeMergeValue($medicine->type),
            $this->normalizeMergeValue($medicine->reorder_level),
            (int) $medicine->is_dangerous,
            (int) $medicine->needs_protection,
        ]);
    }

    private function batchMergeKey(?Batch $batch): string
    {
        if (!$batch) {
            return 'missing-batch';
        }

        return implode('|', [
            $this->normalizeMergeValue($batch->expiry_date),
            $this->normalizeMergeValue($batch->received_date),
        ]);
    }

    private function normalizeMergeValue(mixed $value): string
    {
        return strtolower(trim((string) ($value ?? '')));
    }
}
