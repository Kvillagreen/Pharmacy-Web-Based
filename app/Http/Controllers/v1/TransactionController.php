<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\v1\MethodTransactionRequest;
use App\Models\v1\Batch;
use App\Models\v1\Inventory;
use App\Models\v1\Medicine;
use App\Models\v1\RegulatedCustomer;
use App\Models\v1\Transaction;
use App\Models\v1\TransactionAttachment;
use App\Models\v1\TransactionItem;
use App\Models\v1\UserNotification;
use App\Services\v1\FilesApi;
use App\Services\v1\DocumentStorageService;
use App\Services\v1\MedicineQuery;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
                'voided_at' => $transaction->voided_at,
                'void_reason' => $transaction->void_reason,
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

        $query->where('branches.status', 'active')
        ->where(function ($statusQuery) {
            $statusQuery->whereNull('medicines.status')
                ->orWhere('medicines.status', 'active');
        })
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
                    $transactionItems[] = [
                        'transaction_id' => $transaction->transaction_id,
                        'medicine_id' => (int) $medicineId,
                        'batch_id' => $inventoryBatch->batch_id,
                        'batch_number' => $inventoryBatch->batch_number,
                        'expiry_date' => $inventoryBatch->expiry_date,
                        'mfg_date' => $inventoryBatch->mfg_date,
                        'quantity' => $deductedStocks,
                        'price' => $inventoryBatch->price,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

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

    public function destroy(string $id)
    {
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
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction is already voided.',
                ], 422);
            }

            $medicineIds = collect();

            foreach ($transaction->items as $item) {
                $inventory = Inventory::query()
                    ->where('branch_id', $transaction->branch_id)
                    ->where('medicine_id', $item->medicine_id)
                    ->where('batch_id', $item->batch_id)
                    ->lockForUpdate()
                    ->first();

                if ($inventory) {
                    $inventory->increment('stocks', (int) $item->quantity);
                    $medicineIds->push((int) $item->medicine_id);
                }
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
        return null;
    }

    private function storeTransactionDocuments(Request $request, Transaction $transaction, array $data, ?string $regulatedClassification): array
    {
        if ($regulatedClassification === null) {
            return ['uploaded' => [], 'failed' => []];
        }

        $filesApi = app(FilesApi::class);
        $localStore = app(DocumentStorageService::class);
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

            // create attachment record in pending state first
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
                if (app()->environment('production')) {
                    $remote = $filesApi->upload($file, $spec['category'], $metadata);
                    $uuid = (string) $remote['uuid'];

                    $attachment->update([
                        'remote_uuid' => $uuid,
                        'status' => 'active',
                        'metadata' => array_merge($metadata, ['remote' => $remote]),
                        'uploaded_at' => now(),
                    ]);

                    if ($spec['field'] === 'prescription') {
                        $transaction->forceFill([
                            'prescription_file_uuid' => $uuid,
                            'prescription_path' => 'files-api:' . $uuid,
                        ])->save();
                    }

                    if ($spec['field'] === 'member_id_image') {
                        $transaction->forceFill([
                            'member_id_image_file_uuid' => $uuid,
                            'member_id_image_path' => 'files-api:' . $uuid,
                        ])->save();
                    }
                } else {
                    // non-production: store locally using Laravel storage
                    $relativePath = $localStore->store($file, 'transactions/documents', $spec['category']);

                    if ($relativePath === null) {
                        throw new \RuntimeException('Failed to store file locally.');
                    }

                    $attachment->update([
                        'status' => 'active',
                        'metadata' => array_merge($metadata, ['local' => true, 'path' => $relativePath]),
                        'uploaded_at' => now(),
                    ]);

                    if ($spec['field'] === 'prescription') {
                        $transaction->forceFill([
                            'prescription_file_uuid' => null,
                            'prescription_path' => $relativePath,
                        ])->save();
                    }

                    if ($spec['field'] === 'member_id_image') {
                        $transaction->forceFill([
                            'member_id_image_file_uuid' => null,
                            'member_id_image_path' => $relativePath,
                        ])->save();
                    }
                }

                $uploaded[] = $attachment->fresh();
            } catch (\Throwable $e) {
                // Attempt a local fallback if production upload fails
                try {
                    if (app()->environment('production')) {
                        $relativePath = $localStore->store($file, 'transactions/documents', $spec['category']);
                        if ($relativePath) {
                            $attachment->update([
                                'status' => 'active',
                                'metadata' => array_merge($metadata, ['fallback_local' => true, 'path' => $relativePath, 'failure' => $e->getMessage()]),
                                'uploaded_at' => now(),
                            ]);

                            if ($spec['field'] === 'prescription') {
                                $transaction->forceFill([
                                    'prescription_file_uuid' => null,
                                    'prescription_path' => $relativePath,
                                ])->save();
                            }

                            if ($spec['field'] === 'member_id_image') {
                                $transaction->forceFill([
                                    'member_id_image_file_uuid' => null,
                                    'member_id_image_path' => $relativePath,
                                ])->save();
                            }

                            $uploaded[] = $attachment->fresh();
                            \Illuminate\Support\Facades\Log::warning('Files API upload failed; used local fallback', ['transaction_id' => $transaction->transaction_id, 'field' => $spec['field'], 'error' => $e->getMessage()]);
                            continue;
                        }
                    }
                } catch (\Throwable $fallbackEx) {
                    \Illuminate\Support\Facades\Log::error('Local fallback failed', ['error' => $fallbackEx->getMessage()]);
                }

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
