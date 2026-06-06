<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\v1\MethodMedicineRequest;
use App\Models\v1\Batch;
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
                'batches.expiry_date',
                'batches.received_date',
            ])
            ->where('branches.status', 'active')
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

        $catalog = $query->paginate($perPage);

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
        ->where('batches.expiry_date', '>', now())
        ->where(function ($statusQuery) {
            $statusQuery->whereNull('batches.status')
                ->orWhereNotIn('batches.status', ['pulled_out', 'disposed']);
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

        $paginated = $query->paginate($perPage);

        $inventorySummary = Inventory::query()
            ->join('branches', 'branches.branch_id', '=', 'inventories.branch_id')
            ->join('medicines', 'medicines.medicine_id', '=', 'inventories.medicine_id')
            ->join('batches', 'batches.batch_id', '=', 'inventories.batch_id')
            ->where('branches.status', 'active')
            ->whereDate('batches.expiry_date', '>', $today->toDateString())
            ->where(function ($statusQuery) {
                $statusQuery->whereNull('batches.status')
                    ->orWhereNotIn('batches.status', ['pulled_out', 'disposed']);
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
                    ->orWhereNotIn('batches.status', ['pulled_out', 'disposed']);
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
                'stocks' => $data['stocks'],
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

    private function syncMedicineStocks(int $medicineId): void
    {
        $totalStocks = (int) Inventory::query()
            ->where('medicine_id', $medicineId)
            ->sum('stocks');

        Medicine::query()
            ->where('medicine_id', $medicineId)
            ->update(['stocks' => $totalStocks]);
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
