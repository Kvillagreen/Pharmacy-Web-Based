<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\v1\MethodTransactionRequest;
use App\Models\v1\Batch;
use App\Models\v1\Inventory;
use App\Models\v1\Medicine;
use App\Models\v1\Transaction;
use App\Models\v1\TransactionClaimUpdate;
use App\Models\v1\TransactionItem;
use App\Models\v1\UserNotification;
use App\Services\v1\MedicineQuery;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $site = strtolower($request->header('X-Page-Context', ''));
        $companyId = (int) $request->input('company_id', 0);
        $branchId = (int) $request->input('branch_id', 0);
        $perPage = (int) $request->input('per_page', 10);
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
                'medicines.is_yakap_eligible',
                'medicines.needs_protection',
                'batches.batch_id',
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

        $query->where('branches.status', 'active')
        ->where('medicines.stocks', '>', 0)
        ->where('batches.expiry_date', '>', now())
        ->orderBy('medicines.medicine_name')
        ->orderBy('batches.expiry_date', 'asc')
        ->orderBy('batches.received_date', 'asc')
        ->orderBy('inventories.inventory_id', 'asc');
        if ($request->hasAny(['search', 'sort', 'filter']) || $branchId > 0 || $companyId > 0) {
            $query = (new MedicineQuery())->apply($request, $query);
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

        $criticalCount = Batch::query()
            ->whereHas('inventories', function ($q) use ($branchId, $companyId) {
                $q->join('branches', 'branches.branch_id', '=', 'inventories.branch_id')
                    ->when($branchId > 0, fn ($iq) => $iq->where('inventories.branch_id', $branchId))
                    ->when($branchId <= 0 && $companyId > 0, fn ($iq) => $iq->where('branches.company_id', $companyId))
                    ->where('branches.status', 'active');
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
            ->whereDate('expiry_date', '>=', $today->copy()->addDays(91))
            ->count();

        $expiredCount = Batch::query()
            ->whereHas('inventories', function ($q) use ($branchId, $companyId) {
                $q->join('branches', 'branches.branch_id', '=', 'inventories.branch_id')
                    ->when($branchId > 0, fn ($iq) => $iq->where('inventories.branch_id', $branchId))
                    ->when($branchId <= 0 && $companyId > 0, fn ($iq) => $iq->where('branches.company_id', $companyId))
                    ->where('branches.status', 'active');
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

    public function create(Request $request)
    {
    }

    public function store(MethodTransactionRequest $request)
    {
        DB::beginTransaction();
        $lock = null;

        try {
            $data = $request->validated();

            if (!empty($data['request_token'])) {
                $lock = Cache::lock('transaction_' . $data['request_token'], 10);

                if (!$lock->get()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Duplicate request detected!',
                    ], 429);
                }
            }

            if (empty($data['items'])) {
                throw new \RuntimeException('Invalid items data');
            }

            $requestedQuantities = collect($data['items'])
                ->groupBy('medicine_id')
                ->map(fn ($items) => (int) $items->sum('quantity'));

            $inventoryRows = Inventory::query()
                ->join('medicines', 'medicines.medicine_id', '=', 'inventories.medicine_id')
                ->join('batches', 'batches.batch_id', '=', 'inventories.batch_id')
                ->where('inventories.branch_id', $data['branch_id'])
                ->whereIn('inventories.medicine_id', $requestedQuantities->keys())
                ->orderBy('inventories.medicine_id')
                ->orderBy('batches.expiry_date', 'asc')
                ->orderBy('batches.received_date', 'asc')
                ->orderBy('inventories.inventory_id', 'asc')
                ->lockForUpdate()
                ->get([
                    'inventories.inventory_id',
                    'inventories.branch_id',
                    'inventories.medicine_id',
                    'inventories.batch_id',
                    'inventories.stocks',
                    'medicines.medicine_name',
                    'batches.expiry_date',
                    'batches.received_date',
                ])
                ->groupBy('medicine_id');

            if ($inventoryRows->count() !== $requestedQuantities->count()) {
                throw new \RuntimeException('One or more medicines are not available in the selected branch.');
            }

            foreach ($requestedQuantities as $medicineId => $quantity) {
                $inventoryBatches = $inventoryRows->get($medicineId, collect());
                $totalAvailable = (int) $inventoryBatches->sum(fn ($row) => (int) $row->stocks);
                $medicineName = $inventoryBatches->first()?->medicine_name ?? ('Medicine #' . $medicineId);

                if ($totalAvailable < (int) $quantity) {
                    throw new \RuntimeException("Insufficient stock for {$medicineName}");
                }
            }

            if (($data['transaction_type'] ?? 'regular') === 'yakap') {
                $yakapEligibleIds = Medicine::query()
                    ->whereIn('medicine_id', $requestedQuantities->keys())
                    ->where('is_yakap_eligible', true)
                    ->pluck('medicine_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $invalidMedicineIds = $requestedQuantities->keys()
                    ->map(fn ($id) => (int) $id)
                    ->diff($yakapEligibleIds);

                if ($invalidMedicineIds->isNotEmpty()) {
                    throw new \RuntimeException('Only Yakap eligible medicines can be sold under a Yakap transaction.');
                }
            }

            $transaction = Transaction::create([
                ...collect($data)->except('items')->toArray(),
                'prescription_path' => $this->storeTransactionDocument($request, 'prescription'),
                'member_id_image_path' => $this->storeTransactionDocument($request, 'member_id_image'),
                'documents_submitted' => $this->documentsWereSubmitted($request, $data),
                'claim_status' => $this->resolveInitialClaimStatus($request, $data),
                'documents_completed_at' => $this->documentsWereSubmitted($request, $data) ? now() : null,
            ]);

            $transactionItems = [];
            foreach ($data['items'] as $item) {
                $transactionItems[] = [
                    'transaction_id' => $transaction->transaction_id,
                    'medicine_id' => $item['medicine_id'],
                    'quantity' => $item['quantity'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            TransactionItem::insert($transactionItems);

            foreach ($requestedQuantities as $medicineId => $quantity) {
                $remainingToDeduct = (int) $quantity;
                $inventoryBatches = $inventoryRows->get($medicineId, collect());

                foreach ($inventoryBatches as $inventoryBatch) {
                    if ($remainingToDeduct <= 0) {
                        break;
                    }

                    $availableStocks = (int) $inventoryBatch->stocks;
                    if ($availableStocks <= 0) {
                        continue;
                    }

                    $deductedStocks = min($availableStocks, $remainingToDeduct);
                    $newStocks = $availableStocks - $deductedStocks;

                    Inventory::query()
                        ->where('inventory_id', $inventoryBatch->inventory_id)
                        ->update(['stocks' => $newStocks]);

                    $inventoryBatch->stocks = $newStocks;
                    $remainingToDeduct -= $deductedStocks;
                }

                if ($remainingToDeduct > 0) {
                    throw new \RuntimeException('Unable to complete FEFO stock deduction for one or more medicines.');
                }

                $this->syncMedicineStocks((int) $medicineId);
            }

            $transaction->load(['items.medicine', 'branch:branch_id,branch_name', 'user:user_id,first_name,last_name']);

            $this->createClaimTimeline($transaction, $data);
            $this->createTransactionNotifications($transaction);

            DB::commit();

            return response()->json([
                'message' => 'Transaction created successfully',
                'data' => $transaction,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            \Log::error('Transaction failed', [
                'error' => $e->getMessage(),
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'message' => 'Failed to create transaction',
                'error' => $e->getMessage(),
            ], 500);
        } finally {
            optional($lock)->release();
        }
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

    public function destroy(string $id)
    {
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

    private function createTransactionNotifications(Transaction $transaction): void
    {
        $users = DB::table('users')
            ->where('branch_id', $transaction->branch_id)
            ->where('status', 'approved')
            ->pluck('user_id');

        foreach ($users as $userId) {
            UserNotification::create([
                'user_id' => $userId,
                'branch_id' => $transaction->branch_id,
                'type' => 'transaction',
                'title' => 'New transaction recorded',
                'message' => trim(($transaction->user?->first_name ?? '') . ' ' . ($transaction->user?->last_name ?? ''))
                    . ' processed a transaction at '
                    . ($transaction->branch?->branch_name ?? 'the selected branch')
                    . '.',
                'meta' => [
                    'transaction_id' => $transaction->transaction_id,
                    'amount' => (float) $transaction->total_amount,
                ],
            ]);
        }
    }

    private function storeTransactionDocument(Request $request, string $field): ?string
    {
        if (!$request->hasFile($field)) {
            return null;
        }

        return $request->file($field)->store('transactions/documents', 'public');
    }

    private function documentsWereSubmitted(Request $request, array $data): bool
    {
        if (($data['transaction_type'] ?? 'regular') === 'regular') {
            return false;
        }

        return $request->hasFile('prescription') && $request->hasFile('member_id_image');
    }

    private function resolveInitialClaimStatus(Request $request, array $data): string
    {
        $transactionType = $data['transaction_type'] ?? 'regular';

        if (!in_array($transactionType, ['hmo', 'philhealth'], true)) {
            return 'not_applicable';
        }

        return $this->documentsWereSubmitted($request, $data)
            ? 'documents_ready'
            : 'pending_documents';
    }

    private function createClaimTimeline(Transaction $transaction, array $data): void
    {
        if (!in_array($transaction->transaction_type, ['hmo', 'philhealth'], true)) {
            return;
        }

        TransactionClaimUpdate::create([
            'transaction_id' => $transaction->transaction_id,
            'user_id' => $transaction->user_id,
            'update_type' => 'created',
            'title' => 'Claim transaction created',
            'description' => $transaction->documents_submitted
                ? 'The claim transaction was created with the required documents attached.'
                : 'The claim transaction was created and documents will be submitted later.',
            'meta' => [
                'transaction_type' => $transaction->transaction_type,
                'provider' => $data['hmo_provider'] ?? null,
                'documents_submitted' => $transaction->documents_submitted,
            ],
        ]);
    }
}
