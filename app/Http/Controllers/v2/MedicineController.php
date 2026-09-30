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
use Illuminate\Support\Str;

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
                DB::raw('MAX(inventories.inventory_id) as inventory_id'),
                DB::raw('MAX(inventories.branch_id) as branch_id'),
                DB::raw('MAX(companies.company_name) as company_name'),
                DB::raw(DB::getDriverName() === 'sqlite' ? 'GROUP_CONCAT(DISTINCT branches.branch_name) as branch_name' : 'GROUP_CONCAT(DISTINCT branches.branch_name SEPARATOR ", ") as branch_name'),
                DB::raw('MAX(branches.branch_address) as branch_address'),
                DB::raw(DB::getDriverName() === 'sqlite' ? "REPLACE(GROUP_CONCAT(DISTINCT branches.branch_name || '::' || IFNULL(branches.branch_contact, '')), ',', '||') as branches_data" : "GROUP_CONCAT(DISTINCT CONCAT(branches.branch_name, '::', IFNULL(branches.branch_contact, '')) SEPARATOR '||') as branches_data"),
                DB::raw('MAX(medicines.medicine_id) as medicine_id'),
                'medicines.medicine_name',
                'medicines.generic_name',
                DB::raw('MAX(medicines.category) as category'),
                'medicines.type',
                'medicines.dosage',
                'medicines.unit',
                'medicines.units_per_box',
                DB::raw('MAX(medicines.price) as price'),
                DB::raw('MAX(medicines.reorder_level) as reorder_level'),
                DB::raw('MAX(medicines.is_dangerous) as is_dangerous'),
                DB::raw('MAX(medicines.needs_protection) as needs_protection'),
                DB::raw('SUM(inventories.stocks) as stocks'),
            ])
            ->groupBy([
                'medicines.medicine_name',
                'medicines.generic_name',
                'medicines.type',
                'medicines.dosage',
                'medicines.unit',
                'medicines.units_per_box',
            ])
            ->whereNull('medicines.archived_at')
            ->where('branches.status', 'active')
            ->where('batches.status', 'active')
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
            $query->havingRaw('SUM(inventories.stocks) > 0');
        } elseif ($stockFilter === 'low-stock') {
            $query->havingRaw('SUM(inventories.stocks) <= MAX(medicines.reorder_level)')
                ->havingRaw('SUM(inventories.stocks) > 0');
        } elseif ($stockFilter === 'out-of-stock') {
            $query->havingRaw('SUM(inventories.stocks) <= 0');
        }

        if ($sort === 'stocks') {
            $query->orderByDesc(DB::raw('SUM(inventories.stocks)'))->orderBy('medicines.medicine_name');
        } elseif ($sort === 'price') {
            $query->orderByRaw('MAX(medicines.price)')->orderBy('medicines.medicine_name');
        } elseif ($sort === 'branch') {
            $query->orderByRaw('MIN(branches.branch_name)')->orderBy('medicines.medicine_name');
        } else {
            $query->orderBy('medicines.medicine_name');
        }

        $catalog = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => app(\App\Services\v1\CatalogAvailability::class)->enrich($catalog->items(), $branchId),
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
                'medicines.sku',
                'inventories.branch_id',
                'branches.company_id',
                'inventories.medicine_id',
                'medicines.medicine_name',
                'medicines.generic_name',
                'medicines.category',
                'medicines.pricing_type',
                'medicines.is_vat_exempt',
                'inventories.cost_includes_vat',
                'inventories.cost_price',
                'medicines.markup_percent',
                'medicines.price',
                'medicines.reorder_level',
                'inventories.stocks',
                'medicines.dosage',
                'medicines.unit',
                'medicines.units_per_box',
                DB::raw('((inventories.stocks - (inventories.stocks % (CASE WHEN medicines.units_per_box > 0 THEN medicines.units_per_box ELSE 1 END))) / (CASE WHEN medicines.units_per_box > 0 THEN medicines.units_per_box ELSE 1 END)) as box_count'),
                DB::raw('(inventories.stocks % (CASE WHEN medicines.units_per_box > 0 THEN medicines.units_per_box ELSE 1 END)) as loose_units'),
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
                'batches.supplier',
                'inventories.created_at',
                'inventories.updated_at',
            ])
         ->whereNull('medicines.archived_at')
        ->where('branches.status', 'active')
        ->where(function ($query) {
            $query->whereNull('batches.expiry_date')
                ->orWhereDate('batches.expiry_date', '>', now()->toDateString());
        })
        ->where(function ($statusQuery) {
            $statusQuery->whereNull('batches.status')
                ->orWhere('batches.status', 'active');
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
            ->where(function ($query) use ($today) {
                $query->whereNull('batches.expiry_date')
                    ->orWhereDate('batches.expiry_date', '>', $today->toDateString());
            })
            ->where(function ($statusQuery) {
                $statusQuery->whereNull('batches.status')
                    ->orWhere('batches.status', 'active');
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
                    ->orWhere('batches.status', 'active');
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

    public function archived(Request $request)
    {
        $rows = Inventory::query()->with(['medicine', 'batch', 'branch:branch_id,branch_name'])
            ->whereIn('branch_id', $request->attributes->get('allowed_branch_ids', []))
            ->when((int) $request->input('branch_id') > 0, fn ($q) => $q->where('branch_id', $request->input('branch_id')))
            ->whereHas('medicine', fn ($q) => $q->whereNotNull('archived_at'))
            ->orderByDesc('updated_at')
            ->paginate(max(1, min((int) $request->input('per_page', 10), 100)));
        return response()->json(['success' => true, 'data' => $rows->items(), 'meta' => [
            'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(), 'total' => $rows->total(),
        ]]);
    }

    public function store(MethodMedicineRequest $request)
    {
        DB::beginTransaction();

        try {
            $data = $request->validated();

            if (!empty($data['request_token']) && Cache::has($data['request_token'])) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Duplicate request detected!',
                ], 400);
            }

            if (!empty($data['request_token'])) {
                Cache::put($data['request_token'], true, 30);
            }

            $batchNumber = trim((string) ($data['batch_number'] ?? '')) ?: $this->generateBatchNumber();
            $batch = Batch::where('batch_number', $batchNumber)->lockForUpdate()->first();

            if ($batch && (
                (string) $batch->expiry_date !== (string) $data['expiry_date']
                || (string) $batch->mfg_date !== (string) $data['mfg_date']
            )) {
                throw new \RuntimeException('That batch number already exists with different manufacturing or expiry dates.', 422);
            }

            $supplier = trim((string) ($data['supplier'] ?? ''));
            if ($batch && $supplier !== '' && filled($batch->supplier) && strcasecmp(trim($batch->supplier), $supplier) !== 0) {
                throw new \RuntimeException('This batch already has a different supplier. Correct its supplier through Edit, or use a separate batch reference for a different delivery.', 422);
            }
            if ($batch && $supplier !== '' && !filled($batch->supplier)) {
                if (Inventory::where('batch_id', $batch->batch_id)->whereNotIn('branch_id', $request->attributes->get('allowed_branch_ids', []))->exists()) {
                    throw new \RuntimeException('Supplier cannot be changed on a batch shared with another branch outside your access.', 422);
                }
                $batch->update(['supplier' => $supplier]);
            }
            $sku = \App\Services\v1\ProductIdentity::sku($data);
            DB::table('product_identity_keys')->insertOrIgnore(['sku'=>$sku]);
            DB::table('product_identity_keys')->where('sku',$sku)->lockForUpdate()->first();
            if (Medicine::where('sku', $sku)->count() > 1) throw new \RuntimeException('Duplicate product definitions need review before receiving more stock.', 422);
            $medicine = Medicine::where('sku',$sku)->first() ?? Medicine::firstOrCreate([
                'medicine_name' => trim($data['medicine_name']),
                'generic_name' => trim($data['generic_name']),
                'dosage' => $data['dosage'],
                'unit' => $data['unit'],
                'type' => $data['type'],
                'units_per_box' => $data['units_per_box'],
            ], [
                'category' => $data['category'],
                'pricing_type' => $data['pricing_type'],
                'cost_price' => $data['cost_price'],
                'markup_percent' => $data['markup_percent'],
                'price' => $data['price'],
                'reorder_level' => $data['reorder_level'],
                'stocks' => 0,
                'units_per_box' => $data['units_per_box'],
                'is_dangerous' => (bool) $data['is_dangerous'],
                'needs_protection' => (bool) $data['needs_protection'],
            ]);

            $medicine->update([
                'category' => $data['category'], 'pricing_type' => $data['pricing_type'],
                'cost_price' => $data['cost_price'], 'markup_percent' => $data['markup_percent'],
                'price' => $data['price'],
                'reorder_level' => $data['reorder_level'], 'units_per_box' => $data['units_per_box'],
                'is_dangerous' => (bool) $data['is_dangerous'],
                'needs_protection' => (bool) $data['needs_protection'], 'archived_at' => null,
            ]);

            if (array_key_exists('is_vat_exempt',$data)) $medicine->update(['is_vat_exempt'=>$data['is_vat_exempt']]);
            if (!$batch) {
                $batch = Batch::create([
                    'batch_number' => $batchNumber,
                    'expiry_date' => $data['expiry_date'],
                    'received_date' => $data['received_date'],
                    'mfg_date' => $data['mfg_date'],
                    'location' => $data['location'],
                    'supplier' => $supplier !== '' ? $supplier : null,
                    'status' => 'active',
                ]);
            } elseif (Inventory::where('batch_id', $batch->batch_id)->where('medicine_id', '!=', $medicine->medicine_id)->exists()) {
                throw new \RuntimeException('That batch number is already assigned to a different medicine.', 422);
            }

            $inventory = Inventory::query()->lockForUpdate()->firstOrNew([
                'branch_id' => $data['branch_id'],
                'medicine_id' => $medicine->medicine_id,
                'batch_id' => $batch->batch_id,
            ]);
            $previousStock = (int) ($inventory->stocks ?? 0);
            $previousCost = (float) ($inventory->cost_price ?? $data['cost_price']);
            $incomingStock = (int) $data['stocks'];
            $combinedStock = $previousStock + $incomingStock;
            $inventory->cost_price = $combinedStock > 0
                ? round((($previousStock * $previousCost) + ($incomingStock * (float) $data['cost_price'])) / $combinedStock, 2)
                : (float) $data['cost_price'];
            if ($incomingStock > 0 || !$inventory->exists) {
                $incomingVat = $data['cost_includes_vat'] ?? null;
                $previousVat = $inventory->cost_includes_vat;
                $inventory->cost_includes_vat = $previousStock > 0 &&
                    ($previousVat === null || $incomingVat === null || (string) $previousVat !== (string) $incomingVat)
                    ? null : $incomingVat;
            }
            if (!$inventory->exists) $inventory->stocks = 0;
            $inventory->save();
            $inventory = app(\App\Services\v1\StockMovementService::class)->change($inventory, $incomingStock, 'received', 'Batch #'.$batch->batch_id);

            BatchHistory::create([
                'batch_id' => $batch->batch_id, 'medicine_id' => $medicine->medicine_id,
                'inventory_id' => $inventory->inventory_id, 'branch_id' => $inventory->branch_id,
                'user_id' => auth()->id(), 'action' => $previousStock > 0 ? 'stock_merged' : 'batch_created',
                'quantity_change' => (int) $data['stocks'], 'stock_after' => (int) $inventory->stocks,
                'notes' => $previousStock > 0 ? 'Matching medicine, batch number, manufacturing date, and expiry date merged.' : 'New inventory batch received.',
            ]);

            $this->syncMedicineStocks($medicine->medicine_id);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Medicine, batch, and inventory saved',
                'data' => compact('medicine', 'batch', 'inventory'),
            ], $previousStock > 0 ? 200 : 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Transaction failed',
                'error' => $e->getMessage(),
            ], $e->getCode() === 422 ? 422 : 500);
        }
    }

    public function show(string $id)
    {
        $medicine = Medicine::findOrFail($id);
        $batches = Inventory::query()
            ->with(['batch.histories', 'branch:branch_id,branch_name'])
            ->where('medicine_id', $id)
            ->whereIn('branch_id', request()->attributes->get('allowed_branch_ids', []))
            ->orderBy('batch_id')
            ->get();

        return response()->json(['success' => true, 'data' => ['medicine' => $medicine, 'batches' => $batches]]);
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
            $candidateSku = \App\Services\v1\ProductIdentity::sku([...$medicine->getAttributes(), ...$data]);
            if ($candidateSku !== $medicine->sku && Medicine::where('sku',$candidateSku)->where('medicine_id','!=',$medicine->medicine_id)->exists()) {
                throw new \RuntimeException('That product identity already exists. Review its historical references before merging or changing identity.',422);
            }
            $inventoryId = (int) ($data['inventory_id'] ?? $request->input('inventory_id', 0));

            if ($inventoryId <= 0) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Inventory record is required for this update.',
                ], 422);
            }

            $medicine->update([
                'medicine_name' => $data['medicine_name'],
                'generic_name' => $data['generic_name'],
                'category' => $data['category'],
                'pricing_type' => $data['pricing_type'],
                'cost_price' => $data['cost_price'],
                'markup_percent' => $data['markup_percent'],
                'price' => $data['price'],
                'reorder_level' => $data['reorder_level'],
                'dosage' => $data['dosage'],
                'unit' => $data['unit'],
                'units_per_box' => $data['units_per_box'],
                'type' => $data['type'],
                'needs_protection' => filter_var($data['needs_protection'], FILTER_VALIDATE_BOOLEAN),
                'is_dangerous' => filter_var($data['is_dangerous'], FILTER_VALIDATE_BOOLEAN),
            ]);

            $inventory = Inventory::query()
                ->where('inventory_id', $inventoryId)
                ->where('medicine_id', $medicine->medicine_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (array_key_exists('is_vat_exempt',$data)) $medicine->update(['is_vat_exempt'=>$data['is_vat_exempt']]);
            $inventory->update([
                'cost_price' => $data['cost_price'],
                ... (array_key_exists('cost_includes_vat',$data) ? ['cost_includes_vat'=>$data['cost_includes_vat']] : []),
            ]);

            $inventory = app(\App\Services\v1\StockMovementService::class)->set($inventory, (int) $data['stocks'], 'adjusted', 'Inventory edit');
            $batch = Batch::where('batch_id', $inventory->batch_id)->lockForUpdate()->firstOrFail();
            $data['batch_number'] = trim((string) ($data['batch_number'] ?? '')) ?: ($batch->batch_number ?: $this->generateBatchNumber());
            $duplicateBatch = Batch::query()
                ->where('batch_number', $data['batch_number'])
                ->where('batch_id', '!=', $batch->batch_id)
                ->first();
            if ($duplicateBatch) {
                throw new \RuntimeException('That batch number already exists.', 422);
            }
            $batch->update([
                'batch_number' => $data['batch_number'],
                'expiry_date' => $data['expiry_date'],
                'received_date' => $data['received_date'],
                'status' => 'active',
                'mfg_date' => $data['mfg_date'],
                'location' => $data['location'],
                'supplier' => array_key_exists('supplier', $data) ? (trim((string) $data['supplier']) ?: null) : $batch->supplier,
            ]);

            $this->syncMedicineStocks($medicine->medicine_id);

            BatchHistory::create([
                'batch_id' => $batch->batch_id, 'medicine_id' => $medicine->medicine_id,
                'inventory_id' => $inventory->inventory_id, 'branch_id' => $inventory->branch_id,
                'user_id' => auth()->id(), 'action' => 'batch_updated', 'quantity_change' => 0,
                'stock_after' => (int) $inventory->stocks, 'notes' => 'Medicine or batch details updated.',
            ]);

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
            ], $e->getCode() === 422 ? 422 : 500);
        }
    }

    public function destroy($medicine_id)
    {
        DB::beginTransaction();

        try {
            $medicine = Medicine::where('medicine_id', $medicine_id)->firstOrFail();
            $inventories = Inventory::where('medicine_id', $medicine_id)->get();
            $medicine->update(['archived_at' => now()]);
            Batch::whereIn('batch_id', $inventories->pluck('batch_id'))->update(['status' => 'archived']);
            foreach ($inventories as $inventory) {
                BatchHistory::create([
                    'batch_id' => $inventory->batch_id, 'medicine_id' => $medicine->medicine_id,
                    'inventory_id' => $inventory->inventory_id, 'branch_id' => $inventory->branch_id,
                    'user_id' => auth()->id(), 'action' => 'archived', 'quantity_change' => 0,
                    'stock_after' => (int) $inventory->stocks, 'notes' => 'Medicine archived; inventory retained for audit.',
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Medicine and linked batches archived successfully',
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
        app(\App\Services\v1\StockMovementService::class)->sync($medicineId);
    }

    private function generateBatchNumber(): string
    {
        do {
            $number = 'BAT-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6));
        } while (Batch::where('batch_number', $number)->exists());

        return $number;
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
