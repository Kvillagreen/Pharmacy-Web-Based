<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\v1\Batch;
use App\Models\v1\Inventory;
use App\Services\v1\MedicineQuery;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FefoController extends Controller
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

        $query = Inventory::query()
            ->join('medicines', 'medicines.medicine_id', '=', 'inventories.medicine_id')
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
            ]);

        if ($branchId > 0) {
            $query->where('inventories.branch_id', $branchId);
        } else {
            $query->where('branches.company_id', $companyId);
        }

        if (!empty($fromDate)) {
            $query->whereDate('batches.created_at', '>=', $fromDate);
        }

        if (!empty($toDate)) {
            $query->whereDate('batches.created_at', '<=', $toDate);
        }

        $query->where('branches.status', 'active')
            ->where(function ($statusQuery) {
                $statusQuery->whereNull('batches.status')
                    ->orWhereNotIn('batches.status', ['pulled_out', 'disposed']);
            })
            ->orderBy('batches.expiry_date', 'asc');

        if ($request->hasAny(['search', 'sort', 'filter']) || $branchId > 0 || $companyId > 0) {
            $query = (new MedicineQuery())->apply($request, $query);
        }

        if ($isExport) {
            return response()->json([
                'data' => $query->get(),
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'scope' => $branchId > 0 ? 'branch' : ($companyId > 0 ? 'company' : 'all'),
                'date_field' => 'expiry_date',
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

        $criticalCount = Batch::query()
            ->whereHas('inventories', function ($q) use ($branchId, $companyId) {
                $q->join('branches', 'branches.branch_id', '=', 'inventories.branch_id')
                    ->when($branchId > 0, fn ($iq) => $iq->where('inventories.branch_id', $branchId))
                    ->when($branchId <= 0 && $companyId > 0, fn ($iq) => $iq->where('branches.company_id', $companyId))
                    ->where('branches.status', 'active');
            })
            ->where(function ($statusQuery) {
                $statusQuery->whereNull('status')
                    ->orWhereNotIn('status', ['pulled_out', 'disposed']);
            })
            ->whereDate('expiry_date', '>=', $today)
            ->whereDate('expiry_date', '<=', $today->copy()->addDays(30))
            ->count();

        $warningCount = Batch::query()
            ->whereHas('inventories', function ($q) use ($branchId, $companyId) {
                $q->join('branches', 'branches.branch_id', '=', 'inventories.branch_id')
                    ->when($branchId > 0, fn ($iq) => $iq->where('inventories.branch_id', $branchId))
                    ->when($branchId <= 0 && $companyId > 0, fn ($iq) => $iq->where('branches.company_id', $companyId))
                    ->where('branches.status', 'active');
            })
            ->where(function ($statusQuery) {
                $statusQuery->whereNull('status')
                    ->orWhereNotIn('status', ['pulled_out', 'disposed']);
            })
            ->whereDate('expiry_date', '>=', $today->copy()->addDays(31))
            ->whereDate('expiry_date', '<=', $today->copy()->addDays(90))
            ->count();

        $goodCount = Batch::query()
            ->whereHas('inventories', function ($q) use ($branchId, $companyId) {
                $q->join('branches', 'branches.branch_id', '=', 'inventories.branch_id')
                    ->when($branchId > 0, fn ($iq) => $iq->where('inventories.branch_id', $branchId))
                    ->when($branchId <= 0 && $companyId > 0, fn ($iq) => $iq->where('branches.company_id', $companyId))
                    ->where('branches.status', 'active');
            })
            ->where(function ($statusQuery) {
                $statusQuery->whereNull('status')
                    ->orWhereNotIn('status', ['pulled_out', 'disposed']);
            })
            ->whereDate('expiry_date', '>=', $today->copy()->addDays(91))
            ->count();

        $expiredCount = Batch::query()
            ->whereHas('inventories', function ($q) use ($branchId, $companyId) {
                $q->join('branches', 'branches.branch_id', '=', 'inventories.branch_id')
                    ->when($branchId > 0, fn ($iq) => $iq->where('inventories.branch_id', $branchId))
                    ->when($branchId <= 0 && $companyId > 0, fn ($iq) => $iq->where('branches.company_id', $companyId))
                    ->where('branches.status', 'active');
            })
            ->where(function ($statusQuery) {
                $statusQuery->whereNull('status')
                    ->orWhereNotIn('status', ['pulled_out', 'disposed']);
            })
            ->whereDate('expiry_date', '<', $today)
            ->count();

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
                'low_stock' => (int) ($inventorySummary->low_stock_count ?? 0),
                'warning' => $warningCount,
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

    public function create()
    {
    }

    public function store(Request $request)
    {
    }

    public function show(string $id)
    {
    }

    public function edit(string $id)
    {
    }

    public function update(Request $request, string $id)
    {
    }

    public function pullOut(Request $request, string $batchId)
    {
        $batch = Batch::query()->findOrFail($batchId);

        DB::transaction(function () use ($batch) {
            $medicineIds = Inventory::query()
                ->where('batch_id', $batch->batch_id)
                ->pluck('medicine_id')
                ->unique();

            $batch->update([
                'status' => 'pulled_out',
            ]);

            Inventory::query()
                ->where('batch_id', $batch->batch_id)
                ->update(['stocks' => 0]);

            foreach ($medicineIds as $medicineId) {
                $this->syncMedicineStocks((int) $medicineId);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Expired batch pulled out successfully.',
        ]);
    }

    public function updateLocation(Request $request, string $batchId)
    {
        $data = $request->validate([
            'location' => ['required', 'string', 'max:255'],
        ]);

        $batch = Batch::query()->findOrFail($batchId);
        $batch->update([
            'location' => trim($data['location']),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Batch location updated successfully.',
            'data' => [
                'batch_id' => $batch->batch_id,
                'location' => $batch->location,
            ],
        ]);
    }

    public function destroy(string $id)
    {
    }

    private function syncMedicineStocks(int $medicineId): void
    {
        $totalStocks = (int) Inventory::query()
            ->where('medicine_id', $medicineId)
            ->sum('stocks');

        DB::table('medicines')
            ->where('medicine_id', $medicineId)
            ->update(['stocks' => $totalStocks]);
    }
}
