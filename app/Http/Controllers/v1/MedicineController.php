<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\v1\MethodMedicineRequest;
use App\Models\v1\Batch;
use App\Models\v1\BatchHistory;
use App\Models\v1\Inventory;
use App\Models\v1\Medicine;
use App\Services\v1\MedicineQuery;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MedicineController extends Controller
{
    public function categories(Request $request)
    {
        $companyId = (int) $request->input('company_id', 0);
        $branchId = (int) $request->input('branch_id', 0);
        $scopeBranchIds = DB::table('branches')
            ->where('status', 'active')
            ->when($companyId > 0, fn ($query) => $query->where('company_id', $companyId))
            ->when($branchId > 0, fn ($query) => $query->where('branch_id', $branchId))
            ->pluck('branch_id');
        $hasScopedFilter = $companyId > 0 || $branchId > 0;

        $inventoryCategories = Medicine::query()
            ->join('inventories', 'medicines.medicine_id', '=', 'inventories.medicine_id')
            ->join('branches', 'inventories.branch_id', '=', 'branches.branch_id')
            ->where('branches.status', 'active')
            ->where(function ($statusQuery) {
                $statusQuery->whereNull('medicines.status')
                    ->orWhere('medicines.status', 'active');
            })
            ->when($scopeBranchIds->isNotEmpty(), fn ($query) => $query->whereIn('inventories.branch_id', $scopeBranchIds))
            ->when($hasScopedFilter && $scopeBranchIds->isEmpty(), fn ($query) => $query->whereRaw('1 = 0'))
            ->whereNotNull('medicines.category')
            ->whereRaw("TRIM(medicines.category) <> ''")
            ->distinct()
            ->pluck('medicines.category')
            ->values();

        $transactionCategories = DB::table('transaction_items')
            ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.transaction_id')
            ->join('medicines', 'transaction_items.medicine_id', '=', 'medicines.medicine_id')
            ->when($scopeBranchIds->isNotEmpty(), fn ($query) => $query->whereIn('transactions.branch_id', $scopeBranchIds))
            ->when($hasScopedFilter && $scopeBranchIds->isEmpty(), fn ($query) => $query->whereRaw('1 = 0'))
            ->whereNotNull('medicines.category')
            ->whereRaw("TRIM(medicines.category) <> ''")
            ->distinct()
            ->pluck('medicines.category')
            ->values();

        $globalMedicineCategories = Medicine::query()
            ->whereNotNull('category')
            ->whereRaw("TRIM(category) <> ''")
            ->distinct()
            ->pluck('category')
            ->values();

        $categories = collect($this->pharmacyCategoryCatalog())
            ->merge($inventoryCategories)
            ->merge($transactionCategories)
            ->merge($globalMedicineCategories)
            ->flatMap(fn ($category) => $this->normalizeCategoryValues($category))
            ->unique(fn ($category) => mb_strtolower((string) $category))
            ->sort(fn ($left, $right) => strcasecmp((string) $left, (string) $right))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $categories,
            'count' => $categories->count(),
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'scope' => $branchId > 0 ? 'branch' : ($companyId > 0 ? 'company' : 'all'),
        ]);
    }

    public function publicCatalog(Request $request)
    {
        $perPage = max(6, min((int) $request->input('per_page', 12), 24));
        $branchId = (int) $request->input('branch_id', 0);
        $search = trim((string) $request->input('search', ''));
        $stockFilter = strtolower(trim((string) $request->input('stock_filter', 'all')));
        $sort = strtolower(trim((string) $request->input('sort', 'name')));

        $query = Medicine::query()
            ->join('inventories', 'medicines.medicine_id', '=', 'inventories.medicine_id')
            ->leftJoin('batches', 'inventories.batch_id', '=', 'batches.batch_id')
            ->join('branches', 'inventories.branch_id', '=', 'branches.branch_id')
            ->leftJoin('companies', 'branches.company_id', '=', 'companies.company_id')
            ->select([
                'inventories.inventory_id',
                'inventories.branch_id',
                'companies.company_name',
                'branches.branch_name',
                'branches.branch_address',
                'branches.branch_contact',
                'medicines.medicine_id',
                'medicines.medicine_name',
                'medicines.generic_name',
                'medicines.category',
                'medicines.type',
                'medicines.dosage',
                'medicines.unit',
                'medicines.price',
                'medicines.reorder_level',
                'medicines.is_dangerous',
                'medicines.needs_protection',
                'inventories.stocks',
                'inventories.container_type',
                'inventories.container_name',
                'inventories.container_count',
                'inventories.pcs_per_container',
                'batches.expiry_date',
                'batches.received_date',
            ])
            ->where('branches.status', 'active')
            ->where(function ($statusQuery) {
                $statusQuery->whereNull('medicines.status')
                    ->orWhere('medicines.status', 'active');
            })
            ->where(function ($query) {
                $query->whereNull('batches.expiry_date')
                    ->orWhereDate('batches.expiry_date', '>', now()->toDateString());
            });

        if ($branchId > 0) {
            $query->where('branches.branch_id', $branchId);
        }

        if ($search !== '') {
            $query->where(function ($innerQuery) use ($search) {
                $innerQuery
                    ->where('medicines.medicine_name', 'like', '%' . $search . '%')
                    ->orWhere('medicines.generic_name', 'like', '%' . $search . '%')
                    ->orWhere('medicines.category', 'like', '%' . $search . '%')
                    ->orWhere('medicines.type', 'like', '%' . $search . '%')
                    ->orWhere('branches.branch_name', 'like', '%' . $search . '%');
            });
        }

        if ($stockFilter === 'in-stock') {
            $query->where('inventories.stocks', '>', 0);
        } elseif ($stockFilter === 'low-stock') {
            $query->whereColumn('inventories.stocks', '<=', 'medicines.reorder_level')
                ->where('inventories.stocks', '>', 0);
        } elseif ($stockFilter === 'out-of-stock') {
            $query->where('inventories.stocks', '<=', 0);
        }

        if ($sort === 'stocks') {
            $query->orderByDesc('inventories.stocks')->orderBy('medicines.medicine_name');
        } elseif ($sort === 'price') {
            $query->orderBy('medicines.price')->orderBy('medicines.medicine_name');
        } elseif ($sort === 'branch') {
            $query->orderBy('branches.branch_name')->orderBy('medicines.medicine_name');
        } else {
            $query->orderBy('medicines.medicine_name')->orderBy('branches.branch_name');
        }

        $catalog = \App\Services\v1\MedicineDisplay::paginate($query, $request, $perPage, true);

        return response()->json([
            'success' => true,
            'data' => $catalog->items(),
            'meta' => [
                'current_page' => $catalog->currentPage(),
                'last_page' => $catalog->lastPage(),
                'per_page' => $catalog->perPage(),
                'total' => $catalog->total(),
            ],
            'filters' => [
                'branch_id' => $branchId,
                'search' => $search,
                'stock_filter' => $stockFilter,
                'sort' => $sort,
            ],
        ]);
    }

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
            ->leftJoin('branches', 'inventories.branch_id',
                'branches.branch_name', '=', 'branches.branch_id')
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
                'inventories.container_type',
                'inventories.container_name',
                'inventories.container_count',
                'inventories.pcs_per_container',
                'medicines.dosage',
                'medicines.unit',
                'medicines.type',
                'medicines.is_dangerous',
                'medicines.needs_protection',
                'batches.batch_id',
                'batches.batch_number',
                'batches.expiry_date',
                'batches.received_date',
                'batches.status as batch_status',
                'batches.mfg_date',
                'batches.location',
                'inventories.created_at',
                'inventories.updated_at',
            ])
         ->where('branches.status', 'active')
        ->where(function ($statusQuery) {
            $statusQuery->whereNull('medicines.status')
                ->orWhere('medicines.status', 'active');
        })
        ->where('batches.expiry_date', '>', now())
        ->where(function ($statusQuery) {
            $statusQuery->whereNull('batches.status')
                ->orWhereNotIn('batches.status', ['archived', 'pulled_out', 'disposed', 'deleted']);
        })
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

        $paginated = $request->boolean('group_display')
            ? \App\Services\v1\MedicineDisplay::paginate($query, $request, $perPage)
            : $query->paginate($perPage);

        $inventorySummary = Inventory::query()
            ->join('branches', 'branches.branch_id', '=', 'inventories.branch_id')
            ->join('medicines', 'medicines.medicine_id', '=', 'inventories.medicine_id')
            ->join('batches', 'batches.batch_id', '=', 'inventories.batch_id')
            ->where('branches.status', 'active')
            ->where(function ($statusQuery) {
                $statusQuery->whereNull('medicines.status')
                    ->orWhere('medicines.status', 'active');
            })
            ->whereDate('batches.expiry_date', '>', $today->toDateString())
            ->where(function ($statusQuery) {
                $statusQuery->whereNull('batches.status')
                    ->orWhereNotIn('batches.status', ['archived', 'pulled_out', 'disposed', 'deleted']);
            })
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
            ->where(function ($statusQuery) {
                $statusQuery->whereNull('batches.status')
                    ->orWhereNotIn('batches.status', ['archived', 'pulled_out', 'disposed', 'deleted']);
            })
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

            $stocks = $this->resolveStockCount($data);

            $matchingInventory = $this->findMatchingInventory($data);
            if ($matchingInventory) {
                $matchingInventory->increment('stocks', $stocks);
                $matchingInventory->update($this->containerPayload($data));
                $this->recordBatchHistory((int) $matchingInventory->batch_id, $request, 'stock_merged', 'Matching inventory was merged into this batch.', [
                    'added_stocks' => $stocks,
                ]);
                $this->syncMedicineStocks((int) $matchingInventory->medicine_id);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Matching medicine batch merged successfully',
                    'data' => [
                        'medicine' => $matchingInventory->medicine,
                        'batch' => $matchingInventory->batch,
                        'inventory' => $matchingInventory->fresh(),
                    ],
                ], 200);
            }

            $medicine = Medicine::create([
                'medicine_name' => $data['medicine_name'],
                'generic_name' => $data['generic_name'],
                'category' => $data['category'],
                'price' => $data['price'],
                'reorder_level' => $data['reorder_level'],
                'stocks' => $stocks,
                'dosage' => $data['dosage'],
                'unit' => $data['unit'],
                'type' => $data['type'],
                'is_dangerous' => (bool) $data['is_dangerous'],
                'needs_protection' => (bool) $data['needs_protection'],
                'status' => 'active',
            ]);

            $batch = Batch::create([
                'batch_number' => $data['batch_number'],
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
                'stocks' => $stocks,
                ...$this->containerPayload($data),
            ]);

            $this->syncMedicineStocks($medicine->medicine_id);
            $this->recordBatchHistory((int) $batch->batch_id, $request, 'created', 'Medicine batch added to inventory.', [
                'stocks' => $stocks,
            ]);

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

    public function archived(Request $request)
    {
        $companyId = (int) $request->input('company_id', 0);
        $branchId = (int) $request->input('branch_id', 0);

        $items = Medicine::query()
            ->join('inventories', 'medicines.medicine_id', '=', 'inventories.medicine_id')
            ->leftJoin('batches', 'batches.batch_id', '=', 'inventories.batch_id')
            ->leftJoin('branches', 'branches.branch_id', '=', 'inventories.branch_id')
            ->leftJoin('batch_histories', function ($join) {
                $join->on('batch_histories.batch_id', '=', 'batches.batch_id')
                    ->where('batch_histories.action', 'archived');
            })
            ->where('medicines.status', 'archived')
            ->when($branchId > 0, fn ($q) => $q->where('inventories.branch_id', $branchId))
            ->when($branchId <= 0 && $companyId > 0, fn ($q) => $q->where('branches.company_id', $companyId))
            ->select([
                'medicines.medicine_id',
                'medicines.medicine_name',
                'medicines.generic_name',
                'medicines.category',
                'medicines.type',
                'medicines.dosage',
                'medicines.unit',
                'batches.batch_id',
                'batches.batch_number',
                'batches.status as batch_status',
                'batches.expiry_date',
                'batches.mfg_date',
                'inventories.inventory_id',
                'inventories.stocks',
                'branches.branch_name',
                DB::raw('MAX(batch_histories.created_at) as archived_at'),
            ])
            ->groupBy(
                'medicines.medicine_id',
                'medicines.medicine_name',
                'medicines.generic_name',
                'medicines.category',
                'medicines.type',
                'medicines.dosage',
                'medicines.unit',
                'batches.batch_id',
                'batches.batch_number',
                'batches.status',
                'batches.expiry_date',
                'batches.mfg_date',
                'inventories.inventory_id',
                'inventories.stocks',
                'branches.branch_name'
            )
            ->orderByDesc(DB::raw('COALESCE(MAX(batch_histories.created_at), MAX(medicines.updated_at))'))
            ->get();

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
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
            $inventoryId = (int) ($data['inventory_id'] ?? $request->input('inventory_id', 0));

            if ($inventoryId <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Inventory record is required for this update.',
                ], 422);
            }

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

            $inventory = Inventory::query()
                ->where('inventory_id', $inventoryId)
                ->where('medicine_id', $medicine->medicine_id)
                ->lockForUpdate()
                ->firstOrFail();

            $inventory->update([
                'stocks' => $this->resolveStockCount($data),
                ...$this->containerPayload($data),
            ]);

            $batch = Batch::where('batch_id', $inventory->batch_id)->lockForUpdate()->firstOrFail();
            $batch->update([
                'batch_number' => $data['batch_number'],
                'expiry_date' => $data['expiry_date'],
                'received_date' => $data['received_date'],
                'status' => 'active',
                'mfg_date' => $data['mfg_date'],
                'location' => $data['location'],
            ]);

            $this->recordBatchHistory((int) $batch->batch_id, $request, 'updated', 'Medicine batch details updated.', [
                'stocks' => $inventory->stocks,
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

            Inventory::where('medicine_id', $medicine_id)->update(['stocks' => 0]);
            Batch::whereIn('batch_id', $inventoryBatchIds)->update(['status' => 'archived']);
            $medicine->update([
                'status' => 'archived',
                'stocks' => 0,
            ]);

            foreach ($inventoryBatchIds as $batchId) {
                $this->recordBatchHistory((int) $batchId, request(), 'archived', 'Medicine archived from inventory.');
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Medicine archived successfully',
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

    private function syncMedicineStocks(int $medicineId): void
    {
        $totalStocks = (int) Inventory::query()
            ->where('medicine_id', $medicineId)
            ->sum('stocks');

        Medicine::query()
            ->where('medicine_id', $medicineId)
            ->update(['stocks' => $totalStocks]);
    }

    private function resolveStockCount(array $data): int
    {
        $containerType = strtolower(trim((string) ($data['container_type'] ?? 'none')));
        $containerCount = (int) ($data['container_count'] ?? 0);
        $pcsPerContainer = (int) ($data['pcs_per_container'] ?? 0);

        if (in_array($containerType, ['boxes', 'bulk', 'custom'], true) && $containerCount > 0 && $pcsPerContainer > 0) {
            return $containerCount * $pcsPerContainer;
        }

        return (int) ($data['stocks'] ?? 0);
    }

    private function containerPayload(array $data): array
    {
        $containerType = strtolower(trim((string) ($data['container_type'] ?? 'none')));
        if (!in_array($containerType, ['boxes', 'bulk', 'custom'], true)) {
            $containerType = 'none';
        }

        return [
            'container_type' => $containerType,
            'container_name' => $containerType === 'custom'
                ? (trim((string) ($data['container_name'] ?? '')) ?: null)
                : ($containerType === 'boxes' ? 'Boxes' : ($containerType === 'bulk' ? 'Bulk' : null)),
            'container_count' => $containerType === 'none' ? null : (int) ($data['container_count'] ?? 0),
            'pcs_per_container' => $containerType === 'none' ? null : (int) ($data['pcs_per_container'] ?? 0),
        ];
    }

    private function findMatchingInventory(array $data): ?Inventory
    {
        return Inventory::query()
            ->with(['medicine', 'batch'])
            ->join('medicines', 'medicines.medicine_id', '=', 'inventories.medicine_id')
            ->join('batches', 'batches.batch_id', '=', 'inventories.batch_id')
            ->where('inventories.branch_id', $data['branch_id'])
            ->where('medicines.medicine_name', $data['medicine_name'])
            ->where('medicines.generic_name', $data['generic_name'])
            ->where('medicines.dosage', $data['dosage'])
            ->where('medicines.unit', $data['unit'])
            ->where('medicines.type', $data['type'])
            ->where('batches.batch_number', $data['batch_number'])
            ->whereDate('batches.expiry_date', $data['expiry_date'])
            ->whereDate('batches.mfg_date', $data['mfg_date'])
            ->where(function ($statusQuery) {
                $statusQuery->whereNull('medicines.status')
                    ->orWhere('medicines.status', 'active');
            })
            ->where(function ($statusQuery) {
                $statusQuery->whereNull('batches.status')
                    ->orWhereNotIn('batches.status', ['archived', 'deleted', 'pulled_out', 'disposed']);
            })
            ->select('inventories.*')
            ->lockForUpdate()
            ->first();
    }

    private function recordBatchHistory(int $batchId, Request $request, string $action, string $notes, array $meta = []): void
    {
        BatchHistory::query()->create([
            'batch_id' => $batchId,
            'user_id' => $request->user()?->user_id,
            'action' => $action,
            'notes' => $notes,
            'meta' => $meta ?: null,
        ]);
    }

    private function normalizeCategoryValues($category)
    {
        return collect(preg_split('/[,;|]+/', (string) $category) ?: [])
            ->map(fn ($item) => trim((string) $item))
            ->filter(fn ($item) => $item !== '')
            ->values();
    }

    private function pharmacyCategoryCatalog(): array
    {
        return [
            'Analgesic',
            'Anesthetic',
            'Anti-Allergy',
            'Antacid',
            'Anthelmintic',
            'Anti-Anginal',
            'Anti-Anxiety',
            'Antiarrhythmic',
            'Antiasthmatic',
            'Antibiotic',
            'Anticoagulant',
            'Anticonvulsant',
            'Antidepressant',
            'Antidiabetic',
            'Antidiarrheal',
            'Antidote',
            'Antiemetic',
            'Antifungal',
            'Anti-Gout',
            'Antihistamine',
            'Antihypertensive',
            'Anti-Inflammatory',
            'Antilipidemic',
            'Antimalarial',
            'Antimigraine',
            'Antineoplastic',
            'Antiplatelet',
            'Antipsychotic',
            'Antipyretic',
            'Antiseptic',
            'Antispasmodic',
            'Antitussive',
            'Antivertigo',
            'Antiviral',
            'Bronchodilator',
            'Cardiovascular',
            'Cold and Flu',
            'Contraceptive',
            'Corticosteroid',
            'Cough Preparation',
            'Dermatology',
            'Diagnostic Agent',
            'Diuretic',
            'Electrolyte Replacement',
            'Emergency Medicine',
            'Endocrine',
            'ENT Preparations',
            'Expectorant',
            'Eye Care',
            'Gastrointestinal',
            'Genitourinary',
            'Hematinic',
            'Hormonal Therapy',
            'Hospital Consumable',
            'Immunomodulator',
            'Immunosuppressant',
            'Infant Care',
            'Laxative',
            'Maintenance',
            'Medical Supply',
            'Mineral Supplement',
            'Mucolytic',
            'Muscle Relaxant',
            'Nasal Preparation',
            'Neurology',
            'NSAID',
            'Nutritional Supplement',
            'Obstetrics and Gynecology',
            'Ophthalmic',
            'Otic',
            'Pain Relief',
            'Parenteral Nutrition',
            'Pediatric',
            'Probiotic',
            'Respiratory',
            'Sedative',
            'Sleep Aid',
            'Steroid',
            'Supplement',
            'Topical Preparation',
            'Urologic',
            'Vaccines',
            'Vasodilator',
            'Veterinary',
            'Vitamin',
            'Wound Care',
        ];
    }

}
