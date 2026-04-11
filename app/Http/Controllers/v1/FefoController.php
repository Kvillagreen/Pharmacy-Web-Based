<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\v1\MedicineQuery;
use App\Models\v1\Batch;
use Carbon\Carbon;
use App\Models\v1\Medicine;

class FefoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
{
    $site = strtolower($request->header('X-Page-Context', ''));
    $companyId = (int) $request->input('company_id', 0);
    $branchId  = (int) $request->input('branch_id', 0);
    $perPage   = (int) $request->input('per_page', 10);
    $today     = Carbon::today();

    $query = Medicine::query()
        ->join('inventories', 'medicines.medicine_id', '=', 'inventories.medicine_id')
        ->leftJoin('batches', 'inventories.batch_id', '=', 'batches.batch_id')
        ->leftJoin('branches', 'inventories.branch_id', '=', 'branches.branch_id')
        ->leftJoin('suppliers', 'batches.supplier_id', '=', 'suppliers.supplier_id')
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
            'medicines.stocks',
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
            'suppliers.supplier_id',
            'suppliers.supplier_name',
            'suppliers.supplier_first_name',
            'suppliers.supplier_last_name',
            'suppliers.contact_number',
            'suppliers.address',
            'inventories.created_at',
            'inventories.updated_at',
        ]);

    // 🔥 Scope control
    if ($branchId > 0) {
        $query->where('inventories.branch_id', $branchId);
    } else {
        $query->where('branches.company_id', $companyId);
    }
    $query->where('branches.status', 'active');
    if ($request->hasAny(['search', 'sort', 'filter']) || $branchId > 0 || $companyId > 0) {
        $filter = new MedicineQuery();
        $query = $filter->apply($request, $query);
    }

    $paginated = $query->paginate($perPage);

    $applyScopeToMedicine = function ($q) use ($branchId, $companyId) {
        if ($branchId > 0) {
            $q->whereHas('inventories', fn ($iq) => $iq->where('branch_id', $branchId));
        } elseif ($companyId > 0) {
            $q->whereHas('inventories.branch', fn ($bq) => $bq->where('company_id', $companyId));
        }
        return $q;
    };

    $applyScopeToBatch = function ($q) use ($branchId, $companyId) {
        if ($branchId > 0) {
            $q->whereHas('inventories', fn ($iq) => $iq->where('branch_id', $branchId));
        } elseif ($companyId > 0) {
            $q->whereHas('inventories.branch', fn ($bq) => $bq->where('company_id', $companyId));
        }
        return $q;
    };

    $lowStocks = $applyScopeToMedicine(Medicine::whereColumn('stocks', '<', 'reorder_level'))->count();

    $outOfStock = $applyScopeToMedicine(Medicine::where('stocks', 0))->count();

    $totalAmount = $applyScopeToMedicine(
        Medicine::selectRaw('SUM(price * stocks) as total')
    )->value('total');

    $totalAmount = number_format($totalAmount ?? 0, 2);

    $totalItems = $applyScopeToMedicine(Medicine::query())->count();

    $criticalCount = $applyScopeToBatch(
        Batch::whereDate('expiry_date', '>=', $today)
            ->whereDate('expiry_date', '<=', $today->copy()->addDays(30))
    )->count();

    $warningCount = $applyScopeToBatch(
        Batch::whereDate('expiry_date', '>=', $today->copy()->addDays(31))
            ->whereDate('expiry_date', '<=', $today->copy()->addDays(90))
    )->count();

    $goodCount = $applyScopeToBatch(
        Batch::whereDate('expiry_date', '>=', $today->copy()->addDays(91))
    )->count();

    $expiredCount = $applyScopeToBatch(
        Batch::whereDate('expiry_date', '<', $today)
    )->count();

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
            'critical' => $criticalCount,
            'good' => $goodCount,
            'expired' => $expiredCount,
            'low_stock' => $lowStocks,
            'warning' => $warningCount,
        ]);
    } elseif ($site === 'inventory') {
        $response = array_merge($response, [
            'total_items' => $totalItems,
            'low_stock' => $lowStocks,
            'out_of_stock' => $outOfStock,
            'total_amount' => $totalAmount,
        ]);
    }
    return response()->json($response);
}

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
