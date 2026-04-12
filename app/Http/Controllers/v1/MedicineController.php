<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\v1\Branch;
use Illuminate\Http\Request;
use App\Models\v1\Medicine;
use App\Services\v1\MedicineQuery;
use App\Http\Requests\v1\MethodMedicineRequest;
use Illuminate\Support\Facades\DB;
use App\Models\v1\Batch;
use App\Models\v1\Inventory;
use App\Models\v1\Supplier;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
class MedicineController extends Controller
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
    $isExport  = filter_var($request->input('export', false), FILTER_VALIDATE_BOOLEAN);
    $fromDate  = $request->input('from_date');
    $toDate    = $request->input('to_date');
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
    $query->where('branches.status', 'active');
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
        $filter = new MedicineQuery();
        $query = $filter->apply($request, $query);
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

    $medicineSummary = Medicine::query()
        ->selectRaw('
            COUNT(*) as total_items,
            COALESCE(SUM(price * stocks), 0) as total_amount,
            SUM(CASE WHEN stocks < reorder_level THEN 1 ELSE 0 END) as low_stock_count,
            SUM(CASE WHEN stocks = 0 THEN 1 ELSE 0 END) as out_of_stock_count
        ')
        ->whereExists(function ($exists) use ($branchId, $companyId) {
            $exists->select(DB::raw(1))
                ->from('inventories')
                ->join('branches', 'branches.branch_id', '=', 'inventories.branch_id')
                ->whereColumn('inventories.medicine_id', 'medicines.medicine_id')
                ->where('branches.status', 'active')
                ->when($branchId > 0, fn ($query) => $query->where('inventories.branch_id', $branchId))
                ->when($branchId <= 0 && $companyId > 0, fn ($query) => $query->where('branches.company_id', $companyId));
        })
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
        ->when($branchId > 0, fn ($query) => $query->where('inventories.branch_id', $branchId))
        ->when($branchId <= 0 && $companyId > 0, fn ($query) => $query->where('branches.company_id', $companyId))
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
            'low_stock' => (int) ($medicineSummary->low_stock_count ?? 0),
            'warning' => (int) ($batchSummary->warning_count ?? 0),
        ]);
    } elseif ($site === 'inventory') {
        $response = array_merge($response, [
            'total_items' => (int) ($medicineSummary->total_items ?? 0),
            'low_stock' => (int) ($medicineSummary->low_stock_count ?? 0),
            'out_of_stock' => (int) ($medicineSummary->out_of_stock_count ?? 0),
            'total_amount' => number_format((float) ($medicineSummary->total_amount ?? 0), 2),
        ]);
    }

    return response()->json($response);
}
    /**
     * Store a newly created resource in storage.
     */
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

        // Store the token for 30 seconds
        if (!empty($data['request_token'])) {
            Cache::put($data['request_token'], true, 30);
        }

        // 🏥 Medicine
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
            'is_dangerous' =>(bool)$data['is_dangerous'],
            'needs_protection' => (bool)$data['needs_protection'],
        ]);

        // 🏢 Supplier (PREVENT DUPLICATE 🔥)
       $supplier = Supplier::firstOrCreate(
    ['supplier_name' => $data['supplier_name']],
    [
        'supplier_first_name' => $data['supplier_first_name'],
        'supplier_last_name' => $data['supplier_last_name'],
        'contact_number' => $data['contact_number'],
        'address' => $data['address'],
    ]
);

        // 📦 Batch
        $batch = Batch::create([
    'supplier_id' => $supplier->supplier_id,
    'expiry_date' => $data['expiry_date'],
    'received_date' => $data['received_date'],
    'mfg_date' => $data['mfg_date'],
    'location' => $data['location'],
    'status' => 'active',
]);

        // 🏬 Inventory
        $inventory = Inventory::create([
    'branch_id' => $data['branch_id'],
    'medicine_id' => $medicine->medicine_id, // <-- fix here too
    'batch_id' => $batch->batch_id,
]);

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Medicine, Supplier, Batch, Inventory saved',
            'data' => compact('medicine','supplier','batch','inventory')
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Transaction failed',
            'error' => $e->getMessage(),  // This will tell you the exact DB problem
            'trace' => $e->getTraceAsString() // optional for debugging
        ], 500);
    }
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

}
    /**
     * Update the specified resource in storage.
     */
    public function update(MethodMedicineRequest $request, $id)
{
    DB::beginTransaction();

    try {
        $data = $request->validated();

        // 🏥 Update Medicine
        $medicine = Medicine::where('medicine_id', $id)->firstOrFail();
        $medicine->update([
            'medicine_name' => $data['medicine_name'],
            'generic_name' => $data['generic_name'],
            'category' => $data['category'],
            'price' => $data['price'],
            'reorder_level' => $data['reorder_level'],
            'stocks' => $data['stocks'],
            'dosage' => $data['dosage'],
            'unit' => $data['unit'],
            'type' => $data['type'],
            'needs_protection' => filter_var($data['needs_protection'], FILTER_VALIDATE_BOOLEAN),
            'is_dangerous' => filter_var($data['is_dangerous'], FILTER_VALIDATE_BOOLEAN),
        ]);

        // 🏬 Update Inventory (assumes 1 inventory per medicine)
        $inventory = Inventory::where('medicine_id', $medicine->medicine_id)->firstOrFail();
        $inventory->update([
            'branch_id' => $data['branch_id'],
        ]);

        // 📦 Update Batch (linked to inventory)
        $batch = Batch::where('batch_id', $inventory->batch_id)->firstOrFail();
        $batch->update([
            'expiry_date' =>(date( $data['expiry_date'])),
            'received_date' => (date($data['received_date'])),
            'status' => 'active',
            'mfg_date' => $data['mfg_date'],
            'location' => $data['location'],
        ]);

        // 🏢 Update Supplier (linked to batch)
        $supplier = Supplier::where('supplier_id', $batch->supplier_id)->firstOrFail();
        $supplier->update([
            'supplier_name' => $data['supplier_name'],
            'supplier_first_name' => $data['supplier_first_name'],
            'supplier_last_name' => $data['supplier_last_name'],
            'contact_number' => $data['contact_number'],
            'address' => $data['address'],
        ]);

        DB::commit();

        return response()->json([
            'success' => true,
            'expiry'=> $data['expiry_date'],
            'message' => 'Medicine updated successfully',
            'data' => compact('medicine','supplier','batch','inventory')
        ], 200); // ✅ HTTP 200 OK

    } catch (\Exception $e) {
        DB::rollBack();

        return response()->json([
            'success' => false,
            'message' => 'Update failed',
            'error' => $e->getMessage(),
        ], 500);
    }
}

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($medicine_id)
{
    DB::beginTransaction();

    try {
        // Find Medicine
        $medicine = Medicine::where('medicine_id', $medicine_id)->firstOrFail();

        // Delete all linked inventories first
        Inventory::where('medicine_id', $medicine_id)->delete();

        // Optionally, you could delete batches if needed
        // Batch::whereIn('batch_id', $medicine->inventories->pluck('batch_id'))->delete();

        // Delete the medicine
        $medicine->delete();

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Medicine and linked inventories deleted successfully'
        ], 200);

    } catch (\Exception $e) {
        DB::rollBack();

        return response()->json([
            'success' => false,
            'message' => 'Delete failed',
            'error' => $e->getMessage(),
        ], 500);
    }
}
}
