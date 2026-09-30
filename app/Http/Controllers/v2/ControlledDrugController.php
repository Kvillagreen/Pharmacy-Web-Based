<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\v1\Batch;
use App\Models\v1\Branch;
use App\Models\v1\Medicine;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ControlledDrugController extends Controller
{
    public function index(Request $request)
    {
        $companyId = (int) $request->input('company_id', 0);
        $branchId = (int) $request->input('branch_id', 0);
        $days = max(7, min((int) $request->input('days', 30), 90));
        $perPage = max(5, min((int) $request->input('per_page', 10), 50));
        $search = trim((string) $request->input('search', ''));
        $sort = trim((string) $request->input('sort', 'medicine_name'));

        $today = Carbon::today();
        $rangeStart = Carbon::today()->subDays($days - 1)->startOfDay();
        $rangeEnd = Carbon::today()->endOfDay();

        $scopeBranchIds = Branch::query()
            ->where('status', 'active')
            ->when($companyId > 0, fn ($query) => $query->where('company_id', $companyId))
            ->when($branchId > 0, fn ($query) => $query->where('branch_id', $branchId))
            ->pluck('branch_id');

        $scopeLabel = 'All Branches';
        if ($branchId > 0) {
            $scopeLabel = Branch::query()
                ->where('status', 'active')
                ->where('branch_id', $branchId)
                ->value('branch_name') ?: 'Selected Branch';
        }

        if ($scopeBranchIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'scope' => [
                        'company_id' => $companyId,
                        'branch_id' => $branchId,
                        'days' => $days,
                        'label' => $scopeLabel,
                    ],
                    'summary' => [
                        'total_items' => 0,
                        'dangerous_items' => 0,
                        'protected_items' => 0,
                        'low_stock_items' => 0,
                        'out_of_stock_items' => 0,
                        'expiring_30_count' => 0,
                        'expired_count' => 0,
                        'total_stock_units' => 0,
                        'inventory_value' => 0,
                    ],
                    'inventory' => [
                        'data' => [],
                        'meta' => [
                            'current_page' => 1,
                            'last_page' => 1,
                            'per_page' => $perPage,
                            'total' => 0,
                        ],
                    ],
                    'logbook' => [
                        'data' => [],
                        'total' => 0,
                    ],
                    'analysis' => [
                        'headline' => 'No controlled-drug inventory is available for the selected scope yet.',
                        'highlights' => [],
                    ],
                ],
            ]);
        }

        $inventoryQuery = DB::table('medicines')
            ->join('inventories', 'medicines.medicine_id', '=', 'inventories.medicine_id')
            ->join('branches', 'inventories.branch_id', '=', 'branches.branch_id')
            ->leftJoin('batches', 'inventories.batch_id', '=', 'batches.batch_id')
            ->selectRaw("
                inventories.inventory_id,
                batches.supplier as supplier,
                inventories.branch_id,
                branches.branch_name,
                medicines.medicine_id,
                medicines.medicine_name,
                medicines.generic_name,
                medicines.category,
                medicines.dosage,
                medicines.unit,
                medicines.type,
                medicines.units_per_box,
                medicines.price,
                inventories.stocks,
                ((inventories.stocks - (inventories.stocks % (CASE WHEN medicines.units_per_box > 0 THEN medicines.units_per_box ELSE 1 END))) / (CASE WHEN medicines.units_per_box > 0 THEN medicines.units_per_box ELSE 1 END)) AS box_count,
                (inventories.stocks % (CASE WHEN medicines.units_per_box > 0 THEN medicines.units_per_box ELSE 1 END)) AS loose_units,
                medicines.reorder_level,
                medicines.is_dangerous,
                medicines.needs_protection,
                batches.batch_id,
                batches.batch_number,
                batches.expiry_date,
                batches.mfg_date,
                batches.received_date,
                batches.location,
                batches.status AS batch_status,
                inventories.updated_at
            ")
            ->whereIn('inventories.branch_id', $scopeBranchIds)
            ->whereNull('medicines.archived_at')
            ->where(function ($query) {
                $query->where('medicines.is_dangerous', true)
                    ->orWhere('medicines.needs_protection', true);
            })
            ->where(function ($statusQuery) {
                $statusQuery->whereNull('batches.status')
                    ->orWhereNotIn('batches.status', ['pulled_out', 'disposed', 'archived']);
            })
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($nested) use ($search) {
                    $nested->where('medicines.medicine_name', 'like', '%' . $search . '%')
                        ->orWhere('medicines.generic_name', 'like', '%' . $search . '%')
                        ->orWhere('batches.supplier', 'like', '%' . $search . '%')
                        ->orWhere('medicines.category', 'like', '%' . $search . '%')
                        ->orWhere('batches.location', 'like', '%' . $search . '%');
                });
            });

        $sortField = ltrim($sort, '-');
        $sortDirection = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $sortableColumns = [
            'medicine_id' => 'medicines.medicine_id',
            'medicine_name' => 'medicines.medicine_name',
            'category' => 'medicines.category',
            'branch_name' => 'branches.branch_name',
            'stocks' => 'inventories.stocks',
            'expiry_date' => 'batches.expiry_date',
        ];
        $sortColumn = $sortableColumns[$sortField] ?? 'medicines.medicine_name';

        $inventoryQuery
            ->orderBy($sortColumn, $sortDirection)
            ->orderBy('medicines.medicine_name')
            ->orderBy('branches.branch_name')
            ->orderBy('inventories.inventory_id');

        $paginatedInventory = $inventoryQuery->paginate($perPage);

        $scopedMedicines = Medicine::query()
            ->join('inventories', 'medicines.medicine_id', '=', 'inventories.medicine_id')
            ->leftJoin('batches', 'inventories.batch_id', '=', 'batches.batch_id')
            ->whereIn('inventories.branch_id', $scopeBranchIds)
            ->whereNull('medicines.archived_at')
            ->where(function ($query) {
                $query->where('medicines.is_dangerous', true)
                    ->orWhere('medicines.needs_protection', true);
            })
            ->where(function ($statusQuery) {
                $statusQuery->whereNull('batches.status')
                    ->orWhereNotIn('batches.status', ['pulled_out', 'disposed', 'archived']);
            })
            ->select(
                'medicines.medicine_id',
                'medicines.price',
                'inventories.stocks as stocks',
                'medicines.reorder_level',
                'medicines.is_dangerous',
                'medicines.needs_protection'
            )
            ->get();

        $dangerousItems = (int) $scopedMedicines->where('is_dangerous', true)->count();
        $protectedItems = (int) $scopedMedicines->where('needs_protection', true)->count();
        $lowStockItems = (int) $scopedMedicines
            ->filter(fn ($medicine) => (int) $medicine->stocks <= (int) $medicine->reorder_level)
            ->count();
        $outOfStockItems = (int) $scopedMedicines
            ->filter(fn ($medicine) => (int) $medicine->stocks <= 0)
            ->count();
        $totalStockUnits = (int) $scopedMedicines->sum('stocks');
        $inventoryValue = (float) $scopedMedicines->sum(fn ($medicine) => (float) $medicine->price * (int) $medicine->stocks);

        $expiring30Count = (int) Batch::query()
            ->whereHas('inventories', fn ($query) => $query->whereIn('branch_id', $scopeBranchIds))
            ->where(function ($statusQuery) {
                $statusQuery->whereNull('status')
                    ->orWhereNotIn('status', ['pulled_out', 'disposed']);
            })
            ->whereDate('expiry_date', '>=', $today)
            ->whereDate('expiry_date', '<=', $today->copy()->addDays(30))
            ->count();

        $expiredCount = (int) Batch::query()
            ->whereHas('inventories', fn ($query) => $query->whereIn('branch_id', $scopeBranchIds))
            ->where(function ($statusQuery) {
                $statusQuery->whereNull('status')
                    ->orWhereNotIn('status', ['pulled_out', 'disposed']);
            })
            ->whereDate('expiry_date', '<', $today)
            ->count();

        $logbook = DB::table('transaction_items')
            ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.transaction_id')
            ->join('medicines', 'transaction_items.medicine_id', '=', 'medicines.medicine_id')
            ->join('branches', 'transactions.branch_id', '=', 'branches.branch_id')
            ->leftJoin('users', 'transactions.user_id', '=', 'users.user_id')
            ->leftJoin('batches', 'transaction_items.batch_id', '=', 'batches.batch_id')
            ->whereIn('transactions.branch_id', $scopeBranchIds)
            ->whereBetween('transactions.created_at', [$rangeStart, $rangeEnd])
            ->whereNotNull('transactions.regulated_classification')
            ->where(function ($query) {
                $query->where('medicines.is_dangerous', true)
                    ->orWhere('medicines.needs_protection', true);
            })
            ->select([
                'transaction_items.transaction_item_id',
                'transactions.transaction_id',
                'transactions.reference_number',
                'transactions.status as transaction_status',
                'transactions.payment_method',
                'transactions.created_at as dispensed_at',
                'transactions.regulated_customer_id',
                'transactions.patient_name as customer_name',
                'transactions.customer_contact_number',
                'transactions.customer_id_number',
                'transactions.customer_formatted_address as customer_address',
                'transactions.branch_id',
                'branches.branch_name',
                'medicines.medicine_id',
                'medicines.medicine_name',
                'medicines.generic_name',
                'medicines.is_dangerous',
                'medicines.needs_protection',
                'medicines.price',
                'transaction_items.quantity',
                'transaction_items.batch_id',
                'transaction_items.batch_number',
                'transaction_items.expiry_date',
                'transaction_items.mfg_date',
                'users.first_name',
                'users.last_name',
            ])
            ->orderByDesc('transactions.created_at')
            ->limit(100)
            ->get()
            ->map(function ($row) {
                $classification = $row->is_dangerous && $row->needs_protection
                    ? 'Dangerous / Protected'
                    : ($row->is_dangerous ? 'Dangerous' : 'Protected');

                return [
                    'transaction_item_id' => (int) $row->transaction_item_id,
                    'transaction_id' => (int) $row->transaction_id,
                    'reference_number' => $row->reference_number,
                    'transaction_status' => $row->transaction_status ?: 'completed',
                    'payment_method' => $row->payment_method,
                    'dispensed_at' => $row->dispensed_at,
                    'regulated_customer_id' => $row->regulated_customer_id ? (int) $row->regulated_customer_id : null,
                    'customer_name' => $row->customer_name,
                    'customer_contact_number' => $row->customer_contact_number,
                    'customer_id_number' => $row->customer_id_number,
                    'customer_address' => $row->customer_address,
                    'branch_id' => (int) $row->branch_id,
                    'branch_name' => $row->branch_name,
                    'dispenser_name' => trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')),
                    'medicine_id' => (int) $row->medicine_id,
                    'medicine_name' => $row->medicine_name,
                    'generic_name' => $row->generic_name,
                    'is_dangerous' => (bool) $row->is_dangerous,
                    'needs_protection' => (bool) $row->needs_protection,
                    'classification' => $classification,
                    'quantity' => (int) $row->quantity,
                    'price' => (float) ($row->price ?? 0),
                    'line_total' => round((float) ($row->price ?? 0) * (int) $row->quantity, 2),
                    'batch_id' => $row->batch_id ? (int) $row->batch_id : null,
                    'batch_number' => $row->batch_number ?: ($row->batch_id ? 'BT-' . $row->batch_id : null),
                    'expiry_date' => $row->expiry_date,
                    'mfg_date' => $row->mfg_date,
                ];
            })
            ->values();

        $analysis = $this->buildAnalysis([
            'total_items' => (int) $scopedMedicines->count(),
            'dangerous_items' => $dangerousItems,
            'protected_items' => $protectedItems,
            'low_stock_items' => $lowStockItems,
            'out_of_stock_items' => $outOfStockItems,
            'expiring_30_count' => $expiring30Count,
            'expired_count' => $expiredCount,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'scope' => [
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'days' => $days,
                    'label' => $scopeLabel,
                ],
                'summary' => [
                    'total_items' => (int) $scopedMedicines->count(),
                    'dangerous_items' => $dangerousItems,
                    'protected_items' => $protectedItems,
                    'low_stock_items' => $lowStockItems,
                    'out_of_stock_items' => $outOfStockItems,
                    'expiring_30_count' => $expiring30Count,
                    'expired_count' => $expiredCount,
                    'total_stock_units' => $totalStockUnits,
                    'inventory_value' => round($inventoryValue, 2),
                ],
                'inventory' => [
                    'data' => $paginatedInventory->items(),
                    'meta' => [
                        'current_page' => $paginatedInventory->currentPage(),
                        'last_page' => $paginatedInventory->lastPage(),
                        'per_page' => $paginatedInventory->perPage(),
                        'total' => $paginatedInventory->total(),
                    ],
                ],
                'logbook' => [
                    'data' => $logbook,
                    'total' => $logbook->count(),
                ],
                'analysis' => $analysis,
            ],
        ]);
    }

    private function buildAnalysis(array $summary): array
    {
        $headline = 'Controlled-drug monitoring is stable across the selected scope.';
        $highlights = [];

        if ($summary['expired_count'] > 0) {
            $headline = 'Expired controlled-drug batches need immediate review.';
            $highlights[] = $summary['expired_count'] . ' controlled-drug batches are already expired and should be isolated or removed from active circulation.';
        } elseif ($summary['low_stock_items'] > 0) {
            $headline = 'Controlled-drug stock levels need closer replenishment planning.';
            $highlights[] = $summary['low_stock_items'] . ' controlled items are at or below reorder level, with ' . $summary['out_of_stock_items'] . ' already out of stock.';
        }

        if ($summary['expiring_30_count'] > 0) {
            $highlights[] = $summary['expiring_30_count'] . ' controlled-drug batches expire within 30 days, so FEFO handling should be prioritized.';
        }

        if (empty($highlights)) {
            $highlights[] = 'No controlled-drug risk flags were detected for the selected scope yet.';
        }

        return [
            'headline' => $headline,
            'highlights' => $highlights,
        ];
    }

    public function dispose(Request $request, string $batchId)
    {
        $batch = Batch::query()->findOrFail($batchId);

        DB::transaction(function () use ($batch) {
            $medicineIds = DB::table('inventories')
                ->where('batch_id', $batch->batch_id)
                ->pluck('medicine_id')
                ->unique();

            $batch->update([
                'status' => 'disposed',
            ]);

            app(\App\Services\v1\StockMovementService::class)->clearBatch((int) $batch->batch_id, 'disposed');

            foreach ($medicineIds as $medicineId) {
                $this->syncMedicineStocks((int) $medicineId);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Controlled-drug batch disposed successfully.',
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
            'message' => 'Controlled-drug location updated successfully.',
            'data' => [
                'batch_id' => $batch->batch_id,
                'location' => $batch->location,
            ],
        ]);
    }

    private function syncMedicineStocks(int $medicineId): void
    {
        app(\App\Services\v1\StockMovementService::class)->sync($medicineId);
    }
}
