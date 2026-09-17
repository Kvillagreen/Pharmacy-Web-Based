<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\v1\MethodTransactionRequest;
use App\Models\v1\Batch;
use App\Models\v1\BatchHistory;
use App\Models\v1\Inventory;
use App\Models\v1\Medicine;
use App\Models\v1\RegulatedCustomer;
use App\Models\v1\Transaction;
use App\Models\v1\TransactionAttachment;
use App\Models\v1\TransactionItem;
use App\Models\v1\UserNotification;
<<<<<<< HEAD
use App\Services\v1\FilesApi;
=======
use App\Models\v1\SystemAuditLog;
use App\Models\v1\User;
>>>>>>> f828ce2 (Add BIR 2306 records, SMS orders, batch history, inventory revisions)
use App\Services\v1\MedicineQuery;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class TransactionController extends Controller
{
    public function records(Request $request)
    {
        $companyId = (int) $request->input('company_id', 0);
        $branchId = (int) $request->input('branch_id', 0);
        $authUser = $request->user();
        if ($authUser && !in_array($authUser->role, ['admin', 'owner', 'super_admin'], true)) {
            $branchId = (int) $authUser->branch_id;
        }
        $perPage = max(5, min((int) $request->input('per_page', 10), 50));
        $search = trim((string) $request->input('search', ''));
        $paymentMethod = trim((string) $request->input('payment_method', ''));
        $classification = strtolower(trim((string) $request->input('classification', 'all')));
        $sort = strtolower(trim((string) $request->input('sort', 'newest')));

        $query = Transaction::query()
            ->with([
                'branch:branch_id,branch_name,company_id,status',
                'user:user_id,first_name,last_name',
                'items:transaction_item_id,transaction_id,batch_number,batch_id',
            ])
            ->whereHas('branch', function ($branchQuery) use ($companyId, $branchId) {
                $branchQuery->where('status', 'active')
                    ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId))
                    ->when($branchId <= 0 && $companyId > 0, fn ($q) => $q->where('company_id', $companyId));
            });

        if ($paymentMethod !== '') {
            $query->where('payment_method', $paymentMethod);
        }

        if ($classification === 'regular') {
            $query->whereNull('regulated_classification');
        } elseif (in_array($classification, ['controlled', 'dangerous', 'mixed'], true)) {
            $query->where('regulated_classification', $classification);
        }

        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search) {
                $searchQuery
                    ->where('transaction_id', 'like', '%' . $search . '%')
                    ->orWhere('payment_method', 'like', '%' . $search . '%')
                    ->orWhere('reference_number', 'like', '%' . $search . '%')
                    ->orWhere('patient_name', 'like', '%' . $search . '%')
                    ->orWhereHas('branch', fn ($branchQuery) => $branchQuery->where('branch_name', 'like', '%' . $search . '%'))
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery
                            ->where('first_name', 'like', '%' . $search . '%')
                            ->orWhere('last_name', 'like', '%' . $search . '%');
                    });
            });
        }

        switch ($sort) {
            case 'oldest':
                $query->orderBy('created_at', 'asc')->orderBy('transaction_id', 'asc');
                break;
            case 'amount_desc':
                $query->orderBy('total_amount', 'desc')->orderBy('created_at', 'desc');
                break;
            case 'amount_asc':
                $query->orderBy('total_amount', 'asc')->orderBy('created_at', 'desc');
                break;
            case 'id_asc':
                $query->orderBy('transaction_id', 'asc');
                break;
            case 'id_desc':
                $query->orderBy('transaction_id', 'desc');
                break;
            default:
                $query->orderBy('created_at', 'desc')->orderBy('transaction_id', 'desc');
                break;
        }

        $paginated = $query->paginate($perPage);

        $records = collect($paginated->items())->map(function (Transaction $transaction) {
            return [
                'transaction_id' => $transaction->transaction_id,
                'branch_name' => $transaction->branch?->branch_name,
                'cashier_name' => trim(($transaction->user?->first_name ?? '') . ' ' . ($transaction->user?->last_name ?? '')) ?: 'Unknown Cashier',
                'payment_method' => $transaction->payment_method,
                'reference_number' => $transaction->reference_number,
                'transaction_type' => $transaction->transaction_type,
                'regulated_classification' => $transaction->regulated_classification,
                'patient_name' => $transaction->patient_name,
                'sub_total' => (float) ($transaction->sub_total ?? 0),
                'discount' => (float) ($transaction->discount ?? 0),
                'vat_amount' => (float) ($transaction->vat_amount ?? 0),
                'total_amount' => (float) ($transaction->total_amount ?? 0),
                'used_amount' => (float) ($transaction->used_amount ?? 0),
                'change' => (float) ($transaction->change ?? 0),
                'status' => $transaction->status ?? 'completed',
<<<<<<< HEAD
                'voided_at' => $transaction->voided_at,
                'void_reason' => $transaction->void_reason,
=======
                'void_reason' => $transaction->void_reason,
                'void_supervisor_note' => $transaction->void_supervisor_note,
                'voided_at' => $transaction->voided_at,
                'voided_by_user_id' => $transaction->voided_by_user_id,
                'void_authorized_by_user_id' => $transaction->void_authorized_by_user_id,
                'batch_numbers' => $transaction->items->pluck('batch_number')->filter()->unique()->values()->all(),
>>>>>>> f828ce2 (Add BIR 2306 records, SMS orders, batch history, inventory revisions)
                'created_at' => $transaction->created_at,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $records,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
            'filters' => [
                'search' => $search,
                'payment_method' => $paymentMethod,
                'classification' => $classification,
                'sort' => $sort,
            ],
        ]);
    }

    public function index(Request $request)
    {
        if ($request->user()?->role === 'super_admin') {
            return response()->json(['success' => false, 'message' => 'Super administrator accounts cannot access the POS.'], 403);
        }
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
                'branches.branch_name',
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
                'medicines.units_per_box',
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

<<<<<<< HEAD
        $query->where('branches.status', 'active')
        ->where(function ($statusQuery) {
            $statusQuery->whereNull('medicines.status')
                ->orWhere('medicines.status', 'active');
        })
        ->where('inventories.stocks', '>', 0)
        ->where(function ($statusQuery) {
            $statusQuery->whereNull('batches.status')
                ->orWhereNotIn('batches.status', ['archived', 'pulled_out', 'disposed', 'deleted']);
        })
        ->where('batches.expiry_date', '>', now())
=======
        $query->whereNull('medicines.archived_at')
        ->where('branches.status', 'active')
        ->where('medicines.stocks', '>', 0)
        ->where(function ($query) {
            $query->whereNull('batches.expiry_date')
                ->orWhereDate('batches.expiry_date', '>', now()->toDateString());
        })
>>>>>>> f828ce2 (Add BIR 2306 records, SMS orders, batch history, inventory revisions)
        ->orderBy('medicines.medicine_name')
        ->orderBy('batches.expiry_date', 'asc')
        ->orderBy('batches.received_date', 'asc')
        ->orderBy('inventories.inventory_id', 'asc');
        if ($request->hasAny(['search', 'sort', 'filter']) || $branchId > 0 || $companyId > 0) {
            $query = (new MedicineQuery())->apply($request, $query);
        }

        $paginated = $request->boolean('group_display')
            ? \App\Services\v1\MedicineDisplay::paginate($query, $request, $perPage)
            : $query->paginate($perPage);

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
        if ($request->user()?->role === 'super_admin') {
            return response()->json(['success' => false, 'message' => 'Super administrator accounts cannot process POS sales.'], 403);
        }
        DB::beginTransaction();
        $lock = null;
        $pendingDocumentUploads = [];

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
                    'medicines.price',
                    'inventories.cost_price',
                    'medicines.is_dangerous',
                    'medicines.needs_protection',
                    'batches.batch_number',
                    'batches.expiry_date',
                    'batches.received_date',
                    'batches.mfg_date',
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

            $regulatedClassification = $this->resolveRegulatedClassification($inventoryRows);
            $regulatedCustomer = $regulatedClassification !== null
                ? $this->storeRegulatedCustomer($data)
                : null;
            $regulatedDetails = $this->buildRegulatedDetails($data, $regulatedClassification, $regulatedCustomer?->formatted_address);

            $transaction = Transaction::create([
                ...collect($data)->except('items')->toArray(),
                'vat_amount' => (float) ($data['vat_amount'] ?? 0),
                'status' => 'completed',
                'reference_number' => in_array(($data['payment_method'] ?? ''), ['Card', 'Gcash'], true)
                    ? ($data['reference_number'] ?? null)
                    : null,
                'documents_submitted' => $this->documentsWereSubmitted($request, $regulatedClassification),
                'regulated_customer_id' => $regulatedCustomer?->regulated_customer_id,
                'customer_contact_number' => $regulatedCustomer?->contact_number,
                'customer_id_number' => $regulatedCustomer?->id_number,
                'customer_address_line' => $regulatedCustomer?->address_line,
                'customer_barangay' => $regulatedCustomer?->barangay,
                'customer_city_municipality' => $regulatedCustomer?->city_municipality,
                'customer_province' => $regulatedCustomer?->province,
                'customer_postal_code' => $regulatedCustomer?->postal_code,
                'customer_country' => $regulatedCustomer?->country,
                'customer_formatted_address' => $regulatedCustomer?->formatted_address,
                'regulated_classification' => $regulatedClassification,
                'regulated_details' => $regulatedDetails,
            ]);

            $transactionItems = [];
            foreach (collect($data['items']) as $requestedItem) {
                $medicineId = (int) $requestedItem['medicine_id'];
                $quantity = (int) $requestedItem['quantity'];
                $remainingToDeduct = (int) $quantity;
                $inventoryBatches = $inventoryRows->get($medicineId, collect());

                if (!empty($requestedItem['inventory_id'])) {
                    $inventoryBatches = $inventoryBatches
                        ->where('inventory_id', (int) $requestedItem['inventory_id'])
                        ->values();
                } elseif (!empty($requestedItem['batch_id'])) {
                    $inventoryBatches = $inventoryBatches
                        ->where('batch_id', (int) $requestedItem['batch_id'])
                        ->values();
                }

                if ($inventoryBatches->isEmpty()) {
                    throw new \RuntimeException('The selected medicine batch is not available in this branch.');
                }

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
                    $transactionItems[] = [
                        'transaction_id' => $transaction->transaction_id,
                        'medicine_id' => (int) $medicineId,
                        'batch_id' => $inventoryBatch->batch_id,
                        'batch_number' => $inventoryBatch->batch_number,
                        'expiry_date' => $inventoryBatch->expiry_date,
                        'mfg_date' => $inventoryBatch->mfg_date,
                        'quantity' => $deductedStocks,
                        'price' => $inventoryBatch->price,
                        'cost_price' => $inventoryBatch->cost_price,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    Inventory::query()
                        ->where('inventory_id', $inventoryBatch->inventory_id)
                        ->update(['stocks' => $newStocks]);

                    BatchHistory::create([
                        'batch_id' => $inventoryBatch->batch_id,
                        'medicine_id' => (int) $medicineId,
                        'inventory_id' => $inventoryBatch->inventory_id,
                        'branch_id' => $inventoryBatch->branch_id,
                        'user_id' => $data['user_id'],
                        'action' => 'dispensed',
                        'quantity_change' => -$deductedStocks,
                        'stock_after' => $newStocks,
                        'notes' => 'Stock deducted for transaction #' . $transaction->transaction_id,
                    ]);

                    if ($availableStocks > 0 && $newStocks === 0) {
                        $this->notifyMainBranchOfStockout($inventoryBatch, $transaction->transaction_id);
                    }

                    $inventoryBatch->stocks = $newStocks;
                    $remainingToDeduct -= $deductedStocks;
                }

                if ($remainingToDeduct > 0) {
                    throw new \RuntimeException('The selected batch does not have enough stock to complete the sale.');
                }

                $this->syncMedicineStocks((int) $medicineId);
            }

            TransactionItem::insert($transactionItems);

            $transaction->load([
                'items.medicine',
                'items.batch:batch_id,batch_number,expiry_date,mfg_date',
                'branch:branch_id,branch_name,branch_address,branch_contact',
                'user:user_id,first_name,last_name',
                'regulatedCustomer',
            ]);

            $this->createTransactionNotifications($transaction);

            DB::commit();

            $pendingDocumentUploads = $this->storeTransactionDocuments($request, $transaction, $data, $regulatedClassification);

            if (!empty($pendingDocumentUploads['failed'])) {
                return response()->json([
                    'message' => 'Transaction created, but one or more documents failed to upload.',
                    'data' => $transaction->fresh(['attachments']),
                    'document_errors' => $pendingDocumentUploads['failed'],
                ], 502);
            }

            $transaction->load('attachments');

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
        $transaction = Transaction::query()
            ->with([
                'items.medicine',
                'items.batch:batch_id,batch_number,expiry_date,mfg_date',
                'branch:branch_id,branch_name,branch_address,branch_contact',
                'user:user_id,first_name,last_name',
                'regulatedCustomer',
                'voidedBy:user_id,first_name,last_name',
                'voidAuthorizedBy:user_id,first_name,last_name',
            ])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $transaction,
        ]);
    }

    public function edit(string $id)
    {
    }

    public function update(Request $request, string $id)
    {
    }

    public function destroy(Request $request, string $id)
    {
        $validated = $request->validate([
            'manager_email' => ['required', 'email', 'max:255'],
            'manager_pin' => ['required', 'string', 'regex:/^\d{4,8}$/'],
            'void_reason' => ['required', 'string', 'max:500'],
            'supervisor_note' => ['nullable', 'string', 'max:1000'],
        ], [
            'manager_pin.regex' => 'Manager PIN must contain 4 to 8 digits.',
        ]);

        $cashier = $request->user()?->load('branch');
        $transaction = Transaction::with(['items', 'branch'])->findOrFail($id);

        if ($transaction->status === 'voided') {
            return response()->json([
                'success' => false,
                'message' => 'Transaction is already voided.',
            ], 409);
        }

        if (!$cashier || !$this->userCanAccessTransactionBranch($cashier, $transaction)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to void transactions for this branch.',
            ], 403);
        }

        $authorizationKey = 'transaction-void|' . Str::lower($validated['manager_email']) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($authorizationKey, 5)) {
            return response()->json([
                'success' => false,
                'message' => 'Too many invalid manager PIN attempts. Try again in one minute.',
            ], 429);
        }

        $manager = User::with('branch')
            ->where('email', $validated['manager_email'])
            ->where('status', 'approved')
            ->first();

        $managerIsAuthorized = $manager
            && in_array($manager->role, ['branch_manager', 'owner', 'admin'], true)
            && $manager->manager_pin_hash
            && Hash::check($validated['manager_pin'], $manager->manager_pin_hash)
            && $this->managerCanAuthorizeBranch($manager, $transaction);

        if (!$managerIsAuthorized) {
            RateLimiter::hit($authorizationKey, 60);

            return response()->json([
                'success' => false,
                'message' => 'Manager authorization failed. Check the manager email, PIN, role, and branch assignment.',
            ], 422);
        }

        RateLimiter::clear($authorizationKey);

        try {
            $voidedTransaction = DB::transaction(function () use ($id, $validated, $cashier, $manager, $request) {
                $lockedTransaction = Transaction::with('items')->lockForUpdate()->findOrFail($id);

                if ($lockedTransaction->status === 'voided') {
                    throw new \RuntimeException('Transaction is already voided.', 409);
                }

                foreach ($lockedTransaction->items as $item) {
                    $inventory = Inventory::query()
                        ->where('medicine_id', $item->medicine_id)
                        ->where('batch_id', $item->batch_id)
                        ->where('branch_id', $lockedTransaction->branch_id)
                        ->lockForUpdate()
                        ->first();

                    if ($inventory) {
                        $inventory->increment('stocks', $item->quantity);
                        $inventory->refresh();
                    } else {
                        Inventory::create([
                            'medicine_id' => $item->medicine_id,
                            'batch_id' => $item->batch_id,
                            'branch_id' => $lockedTransaction->branch_id,
                            'stocks' => $item->quantity,
                        ]);
                    }

                    BatchHistory::create([
                        'batch_id' => $item->batch_id, 'medicine_id' => $item->medicine_id,
                        'inventory_id' => $inventory->inventory_id, 'branch_id' => $lockedTransaction->branch_id,
                        'user_id' => $cashier->user_id, 'action' => 'void_restocked',
                        'quantity_change' => (int) $item->quantity, 'stock_after' => (int) $inventory->stocks,
                        'notes' => 'Stock restored by voiding transaction #' . $lockedTransaction->transaction_id,
                    ]);

                    $this->syncMedicineStocks($item->medicine_id);
                }

                $lockedTransaction->update([
                    'status' => 'voided',
                    'void_reason' => $validated['void_reason'],
                    'void_supervisor_note' => $validated['supervisor_note'] ?? null,
                    'voided_at' => now(),
                    'voided_by_user_id' => $cashier->user_id,
                    'void_authorized_by_user_id' => $manager->user_id,
                ]);

                SystemAuditLog::create([
                    'user_id' => $cashier->user_id,
                    'action' => 'transaction_voided',
                    'ip_address' => $request->ip(),
                    'details' => json_encode([
                        'transaction_id' => $lockedTransaction->transaction_id,
                        'branch_id' => $lockedTransaction->branch_id,
                        'amount' => (float) $lockedTransaction->total_amount,
                        'reason' => $validated['void_reason'],
                        'supervisor_note' => $validated['supervisor_note'] ?? null,
                        'authorized_by_user_id' => $manager->user_id,
                    ], JSON_UNESCAPED_SLASHES),
                ]);

                return $lockedTransaction->fresh([
                    'items.medicine',
                    'items.batch:batch_id,batch_number,expiry_date,mfg_date',
                    'branch:branch_id,branch_name,branch_address,branch_contact',
                    'user:user_id,first_name,last_name',
                    'voidedBy:user_id,first_name,last_name',
                    'voidAuthorizedBy:user_id,first_name,last_name',
                ]);
            }, 3);

            return response()->json([
                'success' => true,
                'message' => 'Transaction voided with manager authorization.',
                'data' => $voidedTransaction,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => $e->getCode() === 409
                    ? 'Transaction is already voided.'
                    : 'The transaction could not be voided. No stock changes were committed.',
            ], $e->getCode() === 409 ? 409 : 500);
        }
    }

    private function userCanAccessTransactionBranch(User $user, Transaction $transaction): bool
    {
        if ((int) $user->branch_id === (int) $transaction->branch_id) {
            return true;
        }

        return in_array($user->role, ['owner', 'admin'], true)
            && $user->branch?->company_id
            && (int) $user->branch->company_id === (int) $transaction->branch?->company_id;
    }

    private function managerCanAuthorizeBranch(User $manager, Transaction $transaction): bool
    {
        if ($manager->role === 'branch_manager') {
            return (int) $manager->branch_id === (int) $transaction->branch_id;
        }

        return $manager->branch?->company_id
            && (int) $manager->branch->company_id === (int) $transaction->branch?->company_id;
    }

    public function void(Request $request, string $id)
    {
        $data = $request->validate([
            'void_reason' => ['nullable', 'string', 'max:255'],
        ]);

        DB::beginTransaction();

        try {
            $transaction = Transaction::query()
                ->with('items')
                ->where('transaction_id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            if (($transaction->status ?? 'completed') === 'voided') {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction is already voided.',
                ], 422);
            }

            $medicineIds = collect();

            $user = $request->user();
            $sameCompany = $user?->branch?->company_id &&
                (int) $user->branch->company_id === (int) $transaction->branch?->company_id;
            if (!$user || (!$sameCompany && $user->role !== 'super_admin') ||
                (!in_array($user->role, ['admin', 'owner', 'super_admin'], true) &&
                    (int) $user->branch_id !== (int) $transaction->branch_id)) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'You cannot void transactions outside your assigned scope.'], 403);
            }

            if ($transaction->items->isEmpty()) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'No transaction items are available to restore.'], 422);
            }

            foreach ($transaction->items as $item) {
                $inventory = Inventory::query()
                    ->where('branch_id', $transaction->branch_id)
                    ->where('medicine_id', $item->medicine_id)
                    ->where('batch_id', $item->batch_id)
                    ->lockForUpdate()
                    ->first();

                if (!$item->batch_id || !$inventory || (int) $item->quantity <= 0) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'The original batch inventory could not be restored. No stock changes were saved.'], 422);
                }
                $inventory->increment('stocks', (int) $item->quantity);
                $medicineIds->push((int) $item->medicine_id);
            }

            $transaction->update([
                'status' => 'voided',
                'voided_at' => now(),
                'void_reason' => trim((string) ($data['void_reason'] ?? '')) ?: null,
            ]);

            $medicineIds->unique()->each(fn (int $medicineId) => $this->syncMedicineStocks($medicineId));

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Transaction voided and stocks restored successfully.',
                'data' => $transaction->fresh(),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Unable to void transaction.',
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

    private function notifyMainBranchOfStockout(object $inventoryBatch, int $transactionId): void
    {
        $sourceBranch = \App\Models\v1\Branch::find($inventoryBatch->branch_id);
        if (!$sourceBranch?->company_id) {
            return;
        }

        $recipients = User::query()
            ->where('status', 'approved')
            ->whereIn('role', ['owner', 'admin'])
            ->whereHas('branch', fn ($query) => $query->where('company_id', $sourceBranch->company_id))
            ->get(['user_id']);

        foreach ($recipients as $recipient) {
            UserNotification::create([
                'user_id' => $recipient->user_id,
                'branch_id' => $sourceBranch->branch_id,
                'type' => 'branch_stockout',
                'title' => 'Branch stock-out alert',
                'message' => ($inventoryBatch->medicine_name ?? 'Medicine') . ' is out of stock at ' . $sourceBranch->branch_name . '.',
                'meta' => [
                    'medicine_id' => (int) $inventoryBatch->medicine_id,
                    'batch_id' => (int) $inventoryBatch->batch_id,
                    'transaction_id' => $transactionId,
                    'source_branch_id' => (int) $sourceBranch->branch_id,
                ],
            ]);
        }
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

    private function storeTransactionDocuments(Request $request, Transaction $transaction, array $data, ?string $regulatedClassification): array
    {
        if ($regulatedClassification === null) {
            return ['uploaded' => [], 'failed' => []];
        }

        $filesApi = app(FilesApi::class);
        $uploaded = [];
        $failed = [];

        $documentSpecs = [];
        if ($request->hasFile('prescription')) {
            $documentSpecs[] = [
                'field' => 'prescription',
                'category' => in_array($regulatedClassification, ['dangerous', 'mixed'], true) ? 'dangerous_drug' : 'prescription',
                'label' => in_array($regulatedClassification, ['dangerous', 'mixed'], true) ? 'Yellow Prescription' : 'Prescription',
            ];
        }

        if ($request->hasFile('member_id_image')) {
            $documentSpecs[] = [
                'field' => 'member_id_image',
                'category' => 'valid_id',
                'label' => 'Valid ID / Supporting Image',
            ];
        }

        foreach ($documentSpecs as $spec) {
            $file = $request->file($spec['field']);
            $metadata = $this->filesApiMetadata($transaction, $data, $spec['category']);

            $attachment = TransactionAttachment::query()->create([
                'transaction_id' => $transaction->transaction_id,
                'uploaded_by' => $request->user()?->user_id,
                'category' => $spec['category'],
                'label' => $spec['label'],
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'status' => 'pending_upload',
                'metadata' => $metadata,
            ]);

            try {
                $remote = $filesApi->upload($file, $spec['category'], $metadata);
                $fileId = (string) $remote['file_id'];
                $fileName = (string) $remote['file_name'];

                $attachment->update([
                    'remote_file_id' => $fileId,
                    'remote_file_name' => $fileName,
                    'status' => 'active',
                    'metadata' => array_merge($metadata, ['remote' => $remote]),
                    'uploaded_at' => now(),
                ]);

                if ($spec['field'] === 'prescription') {
                    $transaction->forceFill([
                        'prescription_file_id' => $fileId,
                        'prescription_path' => 'files-api:' . $fileName,
                    ])->save();
                }

                if ($spec['field'] === 'member_id_image') {
                    $transaction->forceFill([
                        'member_id_image_file_id' => $fileId,
                        'member_id_image_path' => 'files-api:' . $fileName,
                    ])->save();
                }

                $uploaded[] = $attachment->fresh();
            } catch (\Throwable $e) {
                $attachment->update([
                    'status' => 'upload_failed',
                    'metadata' => array_merge($metadata, ['failure' => $e->getMessage()]),
                ]);
                $failed[] = [
                    'field' => $spec['field'],
                    'message' => $e->getMessage(),
                ];
            }
        }

        return ['uploaded' => $uploaded, 'failed' => $failed];
    }

    private function filesApiMetadata(Transaction $transaction, array $data, string $category): array
    {
        if (in_array($category, ['document', 'valid_id'], true)) {
            return [];
        }

        $metadata = [
            'record_reference' => 'transaction:' . $transaction->transaction_id,
            'patient_reference' => 'patient:' . md5(strtolower(trim((string) ($data['patient_name'] ?? ''))) . '|' . (string) ($data['customer_id_number'] ?? '') . '|' . (string) ($data['customer_contact_number'] ?? '')),
            'prescriber_name' => trim((string) ($data['prescriber_name'] ?? 'Unknown Prescriber')),
            'prescription_reference' => trim((string) ($data['yellow_prescription_serial_number'] ?? '')) ?: ('transaction:' . $transaction->transaction_id),
            'authorization_reference' => trim((string) ($data['prescriber_prc_license_number'] ?? '')) ?: null,
        ];

        if ($category === 'dangerous_drug') {
            $metadata['drug_name'] = trim((string) ($data['prescribed_brand_name'] ?? $data['prescribed_generic_name'] ?? 'Regulated Drug'));
            $metadata['quantity'] = (string) max(1, (int) ($data['prescribed_quantity_dispensed'] ?? 1));
            $metadata['unit'] = 'pcs';
        }

        return $metadata;
    }

    private function documentsWereSubmitted(Request $request, ?string $regulatedClassification): bool
    {
        if ($regulatedClassification === null) {
            return false;
        }

        if ($regulatedClassification === 'controlled') {
            return $request->hasFile('prescription');
        }

        if (in_array($regulatedClassification, ['dangerous', 'mixed'], true)) {
            return $request->hasFile('prescription') && $request->hasFile('member_id_image');
        }

        return false;
    }

    private function resolveRegulatedClassification($inventoryRows): ?string
    {
        $hasDangerous = collect($inventoryRows)
            ->flatten(1)
            ->contains(fn ($row) => (bool) ($row->is_dangerous ?? false));

        $hasControlled = collect($inventoryRows)
            ->flatten(1)
            ->contains(fn ($row) => (bool) ($row->needs_protection ?? false));

        if ($hasDangerous && $hasControlled) {
            return 'mixed';
        }

        if ($hasDangerous) {
            return 'dangerous';
        }

        if ($hasControlled) {
            return 'controlled';
        }

        return null;
    }

    private function storeRegulatedCustomer(array $data): RegulatedCustomer
    {
        $formattedAddress = $this->formatUnifiedAddress([
            $data['customer_address_line'] ?? null,
            $data['customer_barangay'] ?? null,
            $data['customer_city_municipality'] ?? null,
            $data['customer_province'] ?? null,
            $data['customer_postal_code'] ?? null,
            $data['customer_country'] ?? 'Philippines',
        ]);

        $customer = RegulatedCustomer::query()->firstOrNew([
            'full_name' => trim((string) ($data['patient_name'] ?? '')),
            'contact_number' => trim((string) ($data['customer_contact_number'] ?? '')),
            'id_number' => trim((string) ($data['customer_id_number'] ?? '')),
        ]);

        $customer->fill([
            'address_line' => trim((string) ($data['customer_address_line'] ?? '')) ?: null,
            'barangay' => trim((string) ($data['customer_barangay'] ?? '')) ?: null,
            'city_municipality' => trim((string) ($data['customer_city_municipality'] ?? '')) ?: null,
            'province' => trim((string) ($data['customer_province'] ?? '')) ?: null,
            'postal_code' => trim((string) ($data['customer_postal_code'] ?? '')) ?: null,
            'country' => trim((string) ($data['customer_country'] ?? 'Philippines')) ?: 'Philippines',
            'formatted_address' => $formattedAddress,
            'last_purchase_at' => now(),
        ]);
        $customer->save();

        return $customer;
    }

    private function formatUnifiedAddress(array $parts): ?string
    {
        $filtered = collect($parts)
            ->map(fn ($part) => trim((string) $part))
            ->filter()
            ->values()
            ->all();

        if (empty($filtered)) {
            return null;
        }

        return implode(', ', $filtered);
    }

    private function buildRegulatedDetails(array $data, ?string $regulatedClassification, ?string $formattedAddress): ?array
    {
        if ($regulatedClassification === null) {
            return null;
        }

        $base = [
            'classification' => $regulatedClassification,
            'has_prescription_details' => in_array($regulatedClassification, ['controlled', 'mixed'], true),
            'has_dangerous_drug_details' => in_array($regulatedClassification, ['dangerous', 'mixed'], true),
            'patient_name' => trim((string) ($data['patient_name'] ?? '')) ?: null,
            'patient_address' => $formattedAddress ?: (trim((string) ($data['customer_address_line'] ?? '')) ?: null),
            'patient_contact_number' => trim((string) ($data['customer_contact_number'] ?? '')) ?: null,
            'patient_id_number' => trim((string) ($data['customer_id_number'] ?? '')) ?: null,
            'prescriber_name' => trim((string) ($data['prescriber_name'] ?? '')) ?: null,
        ];

        $details = $base;

        if (in_array($regulatedClassification, ['controlled', 'mixed'], true)) {
            $details['prescription_details'] = array_filter([
                'patient_age' => isset($data['patient_age']) ? (int) $data['patient_age'] : null,
                'prescriber_prc_license_number' => trim((string) ($data['prescriber_prc_license_number'] ?? '')) ?: null,
                'generic_name' => trim((string) ($data['prescribed_generic_name'] ?? '')) ?: null,
                'brand_name' => trim((string) ($data['prescribed_brand_name'] ?? '')) ?: null,
                'dosage_strength' => trim((string) ($data['prescribed_dosage_strength'] ?? '')) ?: null,
                'dosage_form' => trim((string) ($data['prescribed_dosage_form'] ?? '')) ?: null,
                'quantity_dispensed' => isset($data['prescribed_quantity_dispensed']) ? (int) $data['prescribed_quantity_dispensed'] : null,
                'dispensing_date' => $data['dispensing_date'] ?? null,
                'pharmacist_signature' => trim((string) ($data['pharmacist_signature'] ?? '')) ?: null,
            ], fn ($value) => $value !== null && $value !== '');
        }

        if (in_array($regulatedClassification, ['dangerous', 'mixed'], true)) {
            $details['dangerous_drug_details'] = array_filter([
                'prescriber_clinic_address' => trim((string) ($data['prescriber_clinic_address'] ?? '')) ?: null,
                'prescriber_s2_license_number' => trim((string) ($data['prescriber_s2_license_number'] ?? '')) ?: null,
                'prescriber_ptr_number' => trim((string) ($data['prescriber_ptr_number'] ?? '')) ?: null,
                'yellow_prescription_serial_number' => trim((string) ($data['yellow_prescription_serial_number'] ?? '')) ?: null,
                'quantity_in_words' => trim((string) ($data['dangerous_quantity_in_words'] ?? '')) ?: null,
                'quantity_in_figures' => trim((string) ($data['dangerous_quantity_in_figures'] ?? '')) ?: null,
                'total_dosage' => trim((string) ($data['dangerous_total_dosage'] ?? '')) ?: null,
                'treatment_duration' => trim((string) ($data['dangerous_treatment_duration'] ?? '')) ?: null,
                'receiver_name' => trim((string) ($data['receiver_name'] ?? '')) ?: null,
                'receiver_signature' => trim((string) ($data['receiver_signature'] ?? '')) ?: null,
            ], fn ($value) => $value !== null && $value !== '');
        }

        return array_filter($details, fn ($value) => $value !== null && $value !== '' && $value !== []);
    }
}
