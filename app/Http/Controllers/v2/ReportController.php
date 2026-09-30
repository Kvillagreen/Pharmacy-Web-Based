<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\v1\Batch;
use App\Models\v1\Bir2306Record;
use App\Models\v1\Branch;
use App\Services\v1\BatchTrackingReport;
use App\Services\v1\StockHistoryReport;
use App\Models\v1\Medicine;
use App\Models\v1\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function storeBir2306(Request $request)
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,company_id'],
            'branch_id' => ['required', 'integer', 'exists:branches,branch_id'],
            'year' => ['required', 'integer', 'min:2000', 'max:' . (now()->year - 1)],
            'payee_foreign_address' => ['nullable', 'string', 'max:255'],
            'payee_icr_no' => ['nullable', 'string', 'max:50'],
            'payor_tin' => ['required', 'string', 'max:30'],
            'payor_registered_name' => ['required', 'string', 'max:255'],
            'payor_registered_address' => ['required', 'string', 'max:255'],
            'payor_zip_code' => ['required', 'string', 'max:10'],
            'nature_of_income_payment' => ['required', 'string', 'max:255'],
            'atc' => ['required', 'string', 'max:20'],
            'payor_signatory_name' => ['required', 'string', 'max:255'],
            'payor_signatory_title' => ['nullable', 'string', 'max:255'],
            'payor_signatory_tin' => ['prohibited'],
            'certificate_date' => ['prohibited'],
            'payee_signatory_name' => ['required', 'string', 'max:255'],
            'payee_signatory_title' => ['nullable', 'string', 'max:255'],
            'payee_signatory_tin' => ['prohibited'],
            'payee_date_signed' => ['prohibited'],
            'payor_tax_agent_accreditation_no' => ['nullable', 'string', 'max:100'],
            'payor_accreditation_date_issued' => ['nullable', 'date'],
            'payor_accreditation_date_expiry' => ['nullable', 'date'],
            'payor_attorney_roll_no' => ['nullable', 'string', 'max:100'],
            'payee_tax_agent_accreditation_no' => ['nullable', 'string', 'max:100'],
            'payee_accreditation_date_issued' => ['nullable', 'date'],
            'payee_accreditation_date_expiry' => ['nullable', 'date'],
            'payee_attorney_roll_no' => ['nullable', 'string', 'max:100'],
            'substituted_filing_applicable' => ['nullable', 'boolean'],
            'substituted_payor_signatory_name' => ['nullable', 'string', 'max:255'],
            'substituted_payor_signatory_tin' => ['prohibited'],
            'substituted_payor_signatory_title' => ['nullable', 'string', 'max:255'],
            'substituted_payor_date_signed' => ['prohibited'],
            'substituted_payee_signatory_name' => ['nullable', 'string', 'max:255'],
            'substituted_payee_signatory_tin' => ['prohibited'],
            'substituted_payee_signatory_title' => ['nullable', 'string', 'max:255'],
            'substituted_payee_date_signed' => ['prohibited'],
        ]);

        // Signature-only details are completed on the printed document.
        foreach (['payor_signatory_tin', 'payee_signatory_tin', 'substituted_payor_signatory_tin', 'substituted_payee_signatory_tin', 'certificate_date', 'payee_date_signed', 'substituted_payor_date_signed', 'substituted_payee_date_signed'] as $field) {
            $data[$field] = null;
        }

        $branch = Branch::query()
            ->with('company')
            ->where('branch_id', $data['branch_id'])
            ->where('company_id', $data['company_id'])
            ->firstOrFail();

        $periodFrom = Carbon::create((int) $data['year'], 1, 1)->startOfDay();
        $periodTo = Carbon::create((int) $data['year'], 12, 31)->endOfDay();

        $amountOfPayment = round((float) Transaction::query()
            ->where('branch_id', $branch->branch_id)
            ->where('status', '!=', 'voided')
            ->whereBetween('created_at', [$periodFrom, $periodTo])
            ->sum('total_amount'), 2);

        if ($amountOfPayment <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'No completed, non-voided transactions were found for the selected Form 2306 period.',
            ], 422);
        }

        $taxWithheld = round($amountOfPayment * $this->finalWithholdingRateForAtc($data['atc']), 2);

        DB::transaction(function () use ($data, $branch, $periodFrom, $periodTo, $amountOfPayment, $taxWithheld) {
            $sourceReference = 'MANUAL-2306-' . $branch->branch_id . '-'
                . $periodFrom->format('Ymd') . '-' . $periodTo->format('Ymd');

            Bir2306Record::updateOrCreate(
                ['branch_id' => $branch->branch_id, 'source_reference' => $sourceReference],
                [
                    ...collect($data)->except(['year'])->toArray(),
                    'period_from' => $periodFrom->toDateString(),
                    'period_to' => $periodTo->toDateString(),
                    'amount_of_payment' => $amountOfPayment,
                    'tax_withheld' => $taxWithheld,
                    'source_reference' => $sourceReference,
                    'is_test_data' => false,
                ]
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Form 2306 details saved successfully.',
            'data' => [
                'amount_of_payment' => $amountOfPayment,
                'tax_withheld' => $taxWithheld,
            ],
        ]);
    }

    private function finalWithholdingRateForAtc(string $atc): float
    {
        return match (strtoupper(trim($atc))) {
            'WV010', 'WV020' => 0.05,
            default => 0.05,
        };
    }

    public function index(Request $request)
    {
        $companyId = (int) $request->input('company_id', 0);
        $branchId = (int) $request->input('branch_id', 0);
        $authUser = $request->user();
        if ($authUser && !in_array($authUser->role, ['admin', 'owner', 'super_admin'], true)) {
            $branchId = (int) $authUser->branch_id;
        }
        $today = Carbon::today();
        $days = max(7, min((int) $request->input('days', 30), 90));
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        if (!empty($startDate) || !empty($endDate)) {
            if (empty($startDate) || empty($endDate)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Start date and end date are both required for report date filtering.',
                ], 422);
            }

            $rangeStart = Carbon::parse($startDate)->startOfDay();
            $rangeEnd = Carbon::parse($endDate)->endOfDay();

            if ($rangeStart->gt($rangeEnd)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Start date must be earlier than or equal to end date.',
                ], 422);
            }

            $days = $rangeStart->diffInDays($rangeEnd) + 1;
        } else {
            $rangeStart = $today->copy()->subDays($days - 1)->startOfDay();
            $rangeEnd = $today->copy()->endOfDay();
        }

        $previousStart = $rangeStart->copy()->subDays($days);
        $previousEnd = $rangeStart->copy()->subDay()->endOfDay();

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

        $cacheKey = sprintf('report_data_%d_%d_%s_%s_%d_%s', $companyId, $branchId, $startDate ?? '', $endDate ?? '', $days, Cache::get('dashboard_version_'.$companyId, '0'));

        $payload = Cache::remember($cacheKey, 5, function () use ($companyId, $branchId, $startDate, $endDate, $days, $rangeStart, $rangeEnd, $previousStart, $previousEnd, $scopeBranchIds, $scopeLabel, $today) {
            if ($scopeBranchIds->isEmpty()) {
                return [
                        'scope' => [
                            'company_id' => $companyId,
                            'branch_id' => $branchId,
                        'days' => $days,
                        'start_date' => $rangeStart->toDateString(),
                        'end_date' => $rangeEnd->toDateString(),
                        'label' => $scopeLabel,
                    ],
                    'summary' => [
                        'total_revenue' => 0,
                        'previous_revenue' => 0,
                        'revenue_change_pct' => 0,
                        'transaction_count' => 0,
                        'average_sale' => 0,
                        'total_discount' => 0,
                        'cost_of_goods_sold' => 0,
                        'gross_profit' => 0,
                        'gross_margin_pct' => 0,
                        'inventory_value' => 0,
                        'low_stock_count' => 0,
                        'expiring_30_count' => 0,
                    ],
                    'charts' => [
                        'daily_revenue' => [],
                        'daily_transactions' => [],
                        'daily_discounts' => [],
                        'payment_mix' => [],
                        'category_mix' => [],
                        'inventory_status_mix' => [],
                    ],
                    'tables' => [
                        'branch_performance' => [],
                        'top_medicines' => [],
                        'inventory_watch' => [],
                        'recent_transactions' => [],
                        'voided_transactions' => [],
                        'prescribed_transactions' => [],
                        'dangerous_transactions' => [],
                        'batch_tracking' => [],
                        'sales_report' => [],
                    ],
                    'analysis' => [
                        'headline' => 'No report data is available for the selected scope yet.',
                        'highlights' => [],
                    ],
                ];
        }

        $allTransactions = Transaction::query()
            ->whereIn('branch_id', $scopeBranchIds)
            ->where('status', '!=', 'voided');

        $transactionSummary = (clone $allTransactions)
            ->whereIn('branch_id', $scopeBranchIds)
            ->whereBetween('created_at', [$previousStart, $rangeEnd])
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN created_at BETWEEN ? AND ? THEN total_amount ELSE 0 END), 0) as current_revenue,
                 COALESCE(SUM(CASE WHEN created_at BETWEEN ? AND ? THEN total_amount ELSE 0 END), 0) as previous_revenue,
                 SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as transaction_count,
                 COALESCE(SUM(CASE WHEN created_at BETWEEN ? AND ? THEN discount ELSE 0 END), 0) as total_discount',
                [
                    $rangeStart, $rangeEnd,
                    $previousStart, $previousEnd,
                    $rangeStart, $rangeEnd,
                    $rangeStart, $rangeEnd,
                ]
            )
            ->first();

        $currentRevenue = (float) ($transactionSummary->current_revenue ?? 0);
        $previousRevenue = (float) ($transactionSummary->previous_revenue ?? 0);
        $transactionCount = (int) ($transactionSummary->transaction_count ?? 0);
        $averageSale = $transactionCount > 0 ? round($currentRevenue / $transactionCount, 2) : 0;
        $totalDiscount = (float) ($transactionSummary->total_discount ?? 0);

        $costOfGoodsSold = (float) DB::table('transaction_items')
            ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.transaction_id')
            ->whereIn('transactions.branch_id', $scopeBranchIds)
            ->where('transactions.status', '!=', 'voided')
            ->whereBetween('transactions.created_at', [$rangeStart, $rangeEnd])
            ->selectRaw('COALESCE(SUM(transaction_items.quantity * transaction_items.cost_price), 0) as total_cost')
            ->value('total_cost');
        $grossProfit = round($currentRevenue - $costOfGoodsSold, 2);
        $grossMarginPct = $currentRevenue > 0 ? round(($grossProfit / $currentRevenue) * 100, 2) : 0;

        $inventorySummary = DB::table('inventories')
            ->join('medicines', 'medicines.medicine_id', '=', 'inventories.medicine_id')
            ->leftJoin('batches', 'inventories.batch_id', '=', 'batches.batch_id')
            ->selectRaw(
                'COALESCE(SUM(medicines.price * inventories.stocks), 0) as inventory_value,
                 SUM(CASE WHEN inventories.stocks <= medicines.reorder_level THEN 1 ELSE 0 END) as low_stock_count'
            )
            ->whereIn('inventories.branch_id', $scopeBranchIds)
            ->where(function ($statusQuery) {
                $statusQuery->whereNull('batches.status')
                    ->orWhereNotIn('batches.status', ['pulled_out', 'disposed']);
            })
            ->first();

        $inventoryValue = (float) ($inventorySummary->inventory_value ?? 0);
        $lowStockCount = (int) ($inventorySummary->low_stock_count ?? 0);

        $batchSummary = Batch::query()
            ->join('inventories', 'inventories.batch_id', '=', 'batches.batch_id')
            ->whereIn('inventories.branch_id', $scopeBranchIds)
            ->where(function ($statusQuery) {
                $statusQuery->whereNull('batches.status')
                    ->orWhereNotIn('batches.status', ['pulled_out', 'disposed']);
            })
            ->selectRaw(
                'COUNT(DISTINCT CASE WHEN expiry_date >= ? AND expiry_date <= ? THEN batches.batch_id END) as expiring_30_count',
                [$today->toDateString(), $today->copy()->addDays(30)->toDateString()]
            )
            ->first();

        $expiring30Count = (int) ($batchSummary->expiring_30_count ?? 0);

        $dailyRevenueRaw = (clone $allTransactions)
            ->selectRaw('DATE(created_at) as sale_date, SUM(total_amount) as total_revenue, COUNT(*) as transaction_count')
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('sale_date')
            ->get()
            ->keyBy('sale_date');

        $dailyRevenue = collect(range(0, $days - 1))->map(function ($offset) use ($rangeStart, $dailyRevenueRaw) {
            $date = $rangeStart->copy()->addDays($offset)->toDateString();
            $row = $dailyRevenueRaw->get($date);

            return [
                'date' => $date,
                'label' => Carbon::parse($date)->format('M d'),
                'total_revenue' => (float) ($row->total_revenue ?? 0),
                'transaction_count' => (int) ($row->transaction_count ?? 0),
            ];
        })->values();

        $dailyTransactions = $dailyRevenue->map(fn ($point) => [
            'date' => $point['date'],
            'label' => $point['label'],
            'transaction_count' => (int) $point['transaction_count'],
        ])->values();

        $dailyDiscountRaw = (clone $allTransactions)
            ->selectRaw('DATE(created_at) as sale_date, SUM(discount) as total_discount')
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('sale_date')
            ->get()
            ->keyBy('sale_date');

        $dailyDiscounts = collect(range(0, $days - 1))->map(function ($offset) use ($rangeStart, $dailyDiscountRaw) {
            $date = $rangeStart->copy()->addDays($offset)->toDateString();
            $row = $dailyDiscountRaw->get($date);

            return [
                'date' => $date,
                'label' => Carbon::parse($date)->format('M d'),
                'total_discount' => (float) ($row->total_discount ?? 0),
            ];
        })->values();

        $paymentMix = (clone $allTransactions)
            ->selectRaw('payment_method, SUM(total_amount) as total_revenue, COUNT(*) as transaction_count')
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->groupBy('payment_method')
            ->orderByDesc('total_revenue')
            ->get()
            ->map(fn ($row) => [
                'payment_method' => $row->payment_method,
                'total_revenue' => (float) $row->total_revenue,
                'transaction_count' => (int) $row->transaction_count,
            ])
            ->values();

        $categoryMix = DB::table('transaction_items')
            ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.transaction_id')
            ->join('medicines', 'transaction_items.medicine_id', '=', 'medicines.medicine_id')
            ->selectRaw('medicines.category, SUM(transaction_items.quantity) as quantity_sold, COUNT(DISTINCT transactions.transaction_id) as transaction_count')
            ->whereIn('transactions.branch_id', $scopeBranchIds)
            ->where('transactions.status', '!=', 'voided')
            ->whereBetween('transactions.created_at', [$rangeStart, $rangeEnd])
            ->groupBy('medicines.category')
            ->orderByDesc('quantity_sold')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category,
                'quantity_sold' => (int) $row->quantity_sold,
                'transaction_count' => (int) $row->transaction_count,
            ])
            ->values();

        $branchPerformance = Branch::query()
            ->leftJoin('transactions', function ($join) use ($rangeStart, $rangeEnd) {
                $join->on('branches.branch_id', '=', 'transactions.branch_id')
                    ->where('transactions.status', '!=', 'voided')
                    ->whereBetween('transactions.created_at', [$rangeStart, $rangeEnd]);
            })
            ->where('branches.status', 'active')
            ->whereIn('branches.branch_id', $scopeBranchIds)
            ->selectRaw('
                branches.branch_id,
                branches.branch_name,
                COALESCE(SUM(transactions.total_amount), 0) as total_revenue,
                COUNT(transactions.transaction_id) as transaction_count,
                MIN(transactions.created_at) as first_created_at,
                MAX(transactions.created_at) as last_created_at
            ')
            ->groupBy('branches.branch_id', 'branches.branch_name')
            ->orderByDesc('total_revenue')
            ->get()
            ->map(fn ($row) => [
                'branch_id' => $row->branch_id,
                'branch_name' => $row->branch_name,
                'total_revenue' => (float) $row->total_revenue,
                'transaction_count' => (int) $row->transaction_count,
                'first_created_at' => $row->first_created_at,
                'last_created_at' => $row->last_created_at,
            ])
            ->values();

        $topMedicines = DB::table('transaction_items')
            ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.transaction_id')
            ->join('medicines', 'transaction_items.medicine_id', '=', 'medicines.medicine_id')
            ->selectRaw('
                medicines.medicine_id,
                medicines.medicine_name,
                medicines.generic_name,
                medicines.category,
                SUM(transaction_items.quantity) as quantity_sold,
                COUNT(DISTINCT transactions.transaction_id) as transactions_count,
                MIN(transactions.created_at) as first_created_at,
                MAX(transactions.created_at) as last_created_at
            ')
            ->whereIn('transactions.branch_id', $scopeBranchIds)
            ->where('transactions.status', '!=', 'voided')
            ->whereBetween('transactions.created_at', [$rangeStart, $rangeEnd])
            ->groupBy('medicines.medicine_id', 'medicines.medicine_name', 'medicines.generic_name', 'medicines.category')
            ->orderByDesc('quantity_sold')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'medicine_id' => $row->medicine_id,
                'medicine_name' => $row->medicine_name,
                'generic_name' => $row->generic_name,
                'category' => $row->category,
                'quantity_sold' => (int) $row->quantity_sold,
                'transactions_count' => (int) $row->transactions_count,
                'first_created_at' => $row->first_created_at,
                'last_created_at' => $row->last_created_at,
            ])
            ->values();

        $inventoryWatchBase = DB::table('medicines')
            ->join('inventories', 'medicines.medicine_id', '=', 'inventories.medicine_id')
            ->join('branches', 'inventories.branch_id', '=', 'branches.branch_id')
            ->leftJoin('batches', 'inventories.batch_id', '=', 'batches.batch_id')
            ->selectRaw('
                inventories.inventory_id as watch_id,
                inventories.created_at as date,
                medicines.medicine_id,
                medicines.medicine_name,
                medicines.generic_name,
                medicines.category,
                medicines.type,
                medicines.dosage,
                medicines.unit,
                batches.batch_number,
                batches.batch_id,
                batches.supplier,
                batches.expiry_date,
                branches.branch_name,
                branches.branch_id,
                inventories.stocks as current_stock,
                medicines.reorder_level,
                medicines.price,
                inventories.cost_price
            ')
            ->whereIn('inventories.branch_id', $scopeBranchIds)
            ->where(function ($statusQuery) {
                $statusQuery->whereNull('batches.status')
                    ->orWhereNotIn('batches.status', ['pulled_out', 'disposed']);
            })
            ->orderByRaw('CASE WHEN inventories.stocks <= medicines.reorder_level THEN 0 ELSE 1 END')
            ->orderBy('batches.expiry_date')
            ->limit(100)
            ->get();

        $inventoryKeys = $inventoryWatchBase->map(function ($row) {
            return [
                'medicine_id' => $row->medicine_id,
                'branch_id' => $row->branch_id,
                'batch_id' => $row->batch_id,
            ];
        });

        $stockOutData = collect();
        if ($inventoryKeys->isNotEmpty()) {
            $query = DB::table('transaction_items')
                ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.transaction_id')
                ->where('transactions.status', '!=', 'voided')
                ->whereBetween('transactions.created_at', [$rangeStart, $rangeEnd])
                ->selectRaw('
                    transaction_items.medicine_id,
                    transactions.branch_id,
                    transaction_items.batch_id,
                    SUM(transaction_items.quantity) as stock_out,
                    MAX(transactions.created_at) as last_transaction_date,
                    MAX(transactions.transaction_type) as last_transaction_type,
                    MAX(transactions.payment_method) as last_payment_method
                ');

            $query->where(function ($q) use ($inventoryKeys) {
                foreach ($inventoryKeys as $key) {
                    $q->orWhere(function ($sub) use ($key) {
                        $sub->where('transaction_items.medicine_id', $key['medicine_id'])
                            ->where('transactions.branch_id', $key['branch_id']);
                        if ($key['batch_id']) {
                            $sub->where('transaction_items.batch_id', $key['batch_id']);
                        } else {
                            $sub->whereNull('transaction_items.batch_id');
                        }
                    });
                }
            });

            $stockOutData = $query->groupBy('transaction_items.medicine_id', 'transactions.branch_id', 'transaction_items.batch_id')
                ->get()
                ->keyBy(function ($item) {
                    return $item->medicine_id . '-' . $item->branch_id . '-' . ($item->batch_id ?? 'null');
                });
        }

        $inventoryWatch = $inventoryWatchBase
            ->map(function ($row) use ($today, $rangeStart, $rangeEnd, $stockOutData) {
                $key = $row->medicine_id . '-' . $row->branch_id . '-' . ($row->batch_id ?? 'null');
                $so = $stockOutData->get($key);
                
                $row->stock_out = $so ? $so->stock_out : 0;
                $row->last_transaction_date = $so ? $so->last_transaction_date : null;
                $row->last_transaction_type = $so ? $so->last_transaction_type : null;
                $row->last_payment_method = $so ? $so->last_payment_method : null;

                $stockStatus = 'Healthy';
                if ((int) $row->current_stock <= 0) {
                    $stockStatus = 'Out of Stock';
                } elseif ((int) $row->current_stock <= (int) $row->reorder_level) {
                    $stockStatus = 'Low Stock';
                }
                
                $expiryStatus = 'Good';
                if (!empty($row->expiry_date)) {
                    $expDate = Carbon::parse($row->expiry_date);
                    if ($expDate->isPast()) {
                        $expiryStatus = 'Expired';
                    } elseif ($expDate->lt($today->copy()->addDays(30))) {
                        $expiryStatus = 'Expiring Soon';
                    }
                }

                $isNewInPeriod = !empty($row->date) && Carbon::parse($row->date)->between($rangeStart, $rangeEnd);
                
                // Approximate Flow
                $stockOut = (int) $row->stock_out;
                $currentStock = (int) $row->current_stock;
                
                if ($isNewInPeriod) {
                    $stockIn = $currentStock + $stockOut;
                    $beginningStock = 0;
                } else {
                    $stockIn = 0;
                    $beginningStock = $currentStock + $stockOut;
                }
                
                return [
                    'watch_id' => $row->watch_id,
                    'date' => Carbon::parse($row->date)->format('Y-m-d'),
                    'medicine_id' => $row->medicine_id,
                    'medicine_name' => $row->medicine_name,
                    'generic_name' => $row->generic_name,
                    'category' => $row->category,
                    'type' => $row->type ?? '',
                    'dosage' => $row->dosage ?? '',
                    'unit' => $row->unit ?? '',
                    'batch_number' => $row->batch_number ?? '',
                    'expiry_date' => $row->expiry_date,
                    'branch_name' => $row->branch_name,
                    'beginning_stock' => $beginningStock,
                    'stock_in' => $stockIn,
                    'stock_out' => $stockOut,
                    'current_stock' => $currentStock,
                    'reorder_level' => (int) $row->reorder_level,
                    'stock_status' => $stockStatus,
                    'expiry_status' => $expiryStatus,
                    'last_transaction_date' => $row->last_transaction_date,
                    'last_transaction_type' => $row->last_transaction_type,
                    'supplier' => $row->supplier,
                    'unit_price' => (float) $row->price,
                    'inventory_value' => round((float) $row->price * $currentStock, 2),
                    'remarks' => '',
                ];
            })
            ->values();

        $salesReport = Transaction::query()
            ->with(['user:user_id,first_name,last_name', 'branch:branch_id,branch_name'])
            ->whereIn('branch_id', $scopeBranchIds)
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->where('status', '!=', 'voided')
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get()
            ->map(fn ($transaction) => [
                'transaction_id' => $transaction->transaction_id,
                'date' => Carbon::parse($transaction->created_at)->format('Y-m-d H:i:s'),
                'branch_name' => $transaction->branch?->branch_name,
                'customer_name' => $transaction->patient_name ?? '',
                'payment_method' => $transaction->payment_method,
                'sub_total' => (float) $transaction->sub_total,
                'discount' => (float) ($transaction->discount ?? 0),
                'total_amount' => (float) $transaction->total_amount,
                'cashier_name' => trim(($transaction->user?->first_name ?? '') . ' ' . ($transaction->user?->last_name ?? '')),
            ])
            ->values();

        $inventoryStatusSnapshot = DB::table('medicines')
            ->join('inventories', 'medicines.medicine_id', '=', 'inventories.medicine_id')
            ->leftJoin('batches', 'inventories.batch_id', '=', 'batches.batch_id')
            ->whereIn('inventories.branch_id', $scopeBranchIds)
            ->where(function ($statusQuery) {
                $statusQuery->whereNull('batches.status')
                    ->orWhereNotIn('batches.status', ['pulled_out', 'disposed']);
            })
            ->selectRaw('
                SUM(CASE WHEN inventories.stocks <= 0 THEN 1 ELSE 0 END) as out_of_stock_count,
                SUM(CASE WHEN inventories.stocks > 0 AND inventories.stocks <= medicines.reorder_level THEN 1 ELSE 0 END) as low_stock_count,
                SUM(CASE WHEN inventories.stocks > medicines.reorder_level AND batches.expiry_date IS NOT NULL AND batches.expiry_date <= ? THEN 1 ELSE 0 END) as expiring_soon_count,
                SUM(CASE WHEN inventories.stocks > medicines.reorder_level AND (batches.expiry_date IS NULL OR batches.expiry_date > ?) THEN 1 ELSE 0 END) as healthy_count
            ', [
                $today->copy()->addDays(30)->toDateString(),
                $today->copy()->addDays(30)->toDateString(),
            ])
            ->first();

        $inventoryStatusMix = collect([
            ['status' => 'Healthy', 'count' => (int) ($inventoryStatusSnapshot->healthy_count ?? 0)],
            ['status' => 'Low Stock', 'count' => (int) ($inventoryStatusSnapshot->low_stock_count ?? 0)],
            ['status' => 'Out of Stock', 'count' => (int) ($inventoryStatusSnapshot->out_of_stock_count ?? 0)],
            ['status' => 'Expiring Soon', 'count' => (int) ($inventoryStatusSnapshot->expiring_soon_count ?? 0)],
        ])->values();

        $recentTransactions = Transaction::query()
            ->with(['user:user_id,first_name,last_name', 'branch:branch_id,branch_name', 'items:transaction_item_id,transaction_id,batch_number,batch_id'])
            ->whereIn('branch_id', $scopeBranchIds)
            ->where(function ($query) use ($rangeStart, $rangeEnd) {
                $query->whereBetween('created_at', [$rangeStart, $rangeEnd])
                    ->orWhere(function ($voidedQuery) use ($rangeStart, $rangeEnd) {
                        $voidedQuery->where('status', 'voided')
                            ->whereBetween('voided_at', [$rangeStart, $rangeEnd]);
                    });
            })
            ->orderByRaw('COALESCE(voided_at, created_at) DESC')
            ->orderByDesc('transaction_id')
            ->limit(10)
            ->get()
            ->map(fn ($transaction) => [
                'transaction_id' => $transaction->transaction_id,
                'branch_name' => $transaction->branch?->branch_name,
                'cashier_name' => trim(($transaction->user?->first_name ?? '') . ' ' . ($transaction->user?->last_name ?? '')),
                'payment_method' => $transaction->payment_method,
                'reference_number' => $transaction->reference_number,
                'transaction_type' => $transaction->transaction_type,
                'regulated_classification' => $transaction->regulated_classification,
                'patient_name' => $transaction->patient_name,
                'total_amount' => (float) $transaction->total_amount,
                'discount' => (float) ($transaction->discount ?? 0),
                'status' => $transaction->status ?? 'completed',
                'void_reason' => $transaction->void_reason,
                'void_supervisor_note' => $transaction->void_supervisor_note,
                'voided_at' => $transaction->voided_at,
                'batch_numbers' => $transaction->items->pluck('batch_number')->filter()->unique()->values()->all(),
                'created_at' => $transaction->created_at,
            ])
            ->values();

        $voidedTransactions = Transaction::query()
            ->with(['user:user_id,first_name,last_name', 'branch:branch_id,branch_name', 'items:transaction_item_id,transaction_id,batch_number,batch_id'])
            ->whereIn('branch_id', $scopeBranchIds)
            ->where('status', 'voided')
            ->whereBetween('voided_at', [$rangeStart, $rangeEnd])
            ->orderByDesc('voided_at')
            ->orderByDesc('transaction_id')
            ->limit(10)
            ->get()
            ->map(fn ($transaction) => [
                'transaction_id' => $transaction->transaction_id,
                'branch_name' => $transaction->branch?->branch_name,
                'cashier_name' => trim(($transaction->user?->first_name ?? '') . ' ' . ($transaction->user?->last_name ?? '')),
                'payment_method' => $transaction->payment_method,
                'reference_number' => $transaction->reference_number,
                'transaction_type' => $transaction->transaction_type,
                'regulated_classification' => $transaction->regulated_classification,
                'patient_name' => $transaction->patient_name,
                'total_amount' => (float) $transaction->total_amount,
                'discount' => (float) ($transaction->discount ?? 0),
                'status' => 'voided',
                'void_reason' => $transaction->void_reason,
                'void_supervisor_note' => $transaction->void_supervisor_note,
                'voided_at' => $transaction->voided_at,
                'batch_numbers' => $transaction->items->pluck('batch_number')->filter()->unique()->values()->all(),
                'created_at' => $transaction->created_at,
            ])
            ->values();

        $prescribedTransactions = Transaction::query()
            ->with(['user:user_id,first_name,last_name', 'branch:branch_id,branch_name'])
            ->whereIn('branch_id', $scopeBranchIds)
            ->where('status', '!=', 'voided')
            ->whereIn('regulated_classification', ['controlled', 'mixed'])
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($transaction) => $this->mapRegulatedTransaction($transaction))
            ->values();

        $dangerousTransactions = Transaction::query()
            ->with(['user:user_id,first_name,last_name', 'branch:branch_id,branch_name'])
            ->whereIn('branch_id', $scopeBranchIds)
            ->where('status', '!=', 'voided')
            ->whereIn('regulated_classification', ['dangerous', 'mixed'])
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($transaction) => $this->mapRegulatedTransaction($transaction))
            ->values();

        $analysis = $this->buildAnalysis([
            'current_revenue' => $currentRevenue,
            'previous_revenue' => $previousRevenue,
            'transaction_count' => $transactionCount,
            'average_sale' => $averageSale,
            'total_discount' => $totalDiscount,
            'low_stock_count' => $lowStockCount,
            'expiring_30_count' => $expiring30Count,
        ], $paymentMix, $branchPerformance, $topMedicines);

        return [
                'scope' => [
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'days' => $days,
                    'start_date' => $rangeStart->toDateString(),
                    'end_date' => $rangeEnd->toDateString(),
                    'label' => $scopeLabel,
                ],
                'summary' => [
                    'total_revenue' => round($currentRevenue, 2),
                    'previous_revenue' => round($previousRevenue, 2),
                    'revenue_change_pct' => $this->percentChange($currentRevenue, $previousRevenue),
                    'transaction_count' => $transactionCount,
                    'average_sale' => round($averageSale, 2),
                    'total_discount' => round($totalDiscount, 2),
                    'cost_of_goods_sold' => round($costOfGoodsSold, 2),
                    'gross_profit' => $grossProfit,
                    'gross_margin_pct' => $grossMarginPct,
                    'inventory_value' => round($inventoryValue, 2),
                    'low_stock_count' => $lowStockCount,
                    'expiring_30_count' => $expiring30Count,
                ],
                'charts' => [
                    'daily_revenue' => $dailyRevenue,
                    'daily_transactions' => $dailyTransactions,
                    'daily_discounts' => $dailyDiscounts,
                    'payment_mix' => $paymentMix,
                    'category_mix' => $categoryMix,
                    'inventory_status_mix' => $inventoryStatusMix,
                ],
                'tables' => [
                    'branch_performance' => $branchPerformance,
                    'top_medicines' => $topMedicines,
                    'inventory_watch' => $inventoryWatch,
                    'recent_transactions' => $recentTransactions,
                    'voided_transactions' => $voidedTransactions,
                    'prescribed_transactions' => $prescribedTransactions,
                    'dangerous_transactions' => $dangerousTransactions,
                    'batch_tracking' => app(BatchTrackingReport::class)->rows($scopeBranchIds->all(), $rangeStart, $rangeEnd),
                    'sales_report' => $salesReport,
                ],
                'analysis' => $analysis,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $payload,
        ]);
    }

    public function birProfile(Request $request)
    {
        $user = $request->user();
        $companyId = (int) ($request->input('company_id') ?: ($user?->branch?->company_id ?: 0));
        if (!$companyId && $user?->branch_id) {
            $companyId = (int) Branch::where('branch_id', $user->branch_id)->value('company_id');
        }
        $company = $companyId > 0 ? \App\Models\v1\Company::find($companyId) : null;
        if (!$company) {
            $company = \App\Models\v1\Company::first();
            $companyId = $company ? (int) $company->company_id : 0;
        }

        if (!$company) {
            return response()->json([
                'success' => true,
                'data' => [
                    'company_id' => null,
                    'registered_name' => '',
                    'tin' => '',
                    'tax_profile' => null,
                    'branches' => []
                ]
            ]);
        }

        $branches = Branch::where('company_id', $companyId)
            ->when($user && !\App\Services\v1\RoleAccess::managesCompany($user->role), fn($q) => $q->where('branch_id', $user->branch_id))
            ->orderBy('branch_name')
            ->get(['branch_id', 'branch_name', 'status']);

        return response()->json([
            'success' => true,
            'data' => [
                'company_id' => $companyId,
                'registered_name' => $company->company_name,
                'tin' => $company->tin_number,
                'tax_profile' => $company->tax_profile,
                'branches' => $branches
            ]
        ]);
    }

    public function birAnnualDeclaration(Request $request)
    {
        $profile = \App\Models\v1\Company::find($request->input('company_id'))?->tax_profile;
        foreach (['vat_mode','tax_rate_type','deduction_method'] as $setting) {
            if (!empty($profile[$setting])) {
                if ($request->filled($setting) && $request->input($setting) !== $profile[$setting]) {
                    throw \Illuminate\Validation\ValidationException::withMessages([$setting=>'This selection conflicts with the saved pharmacy tax profile.']);
                }
                $request->merge([$setting=>$profile[$setting]]);
            }
        }
        $adjustmentStates=[];
        $optionalAdjustments = ['deductions', 'surcharge', 'interest', 'compromise', 'tax_credits'];
        foreach ($optionalAdjustments as $field) {
            $value = $request->input($field);
            $adjustmentStates[$field]=is_string($value) && strcasecmp(trim($value),'N/A')===0 ? 'not_applicable' : ($value===null || (is_string($value)&&trim($value)==='') ? 'not_entered' : 'entered');
            if (is_string($value) && (trim($value) === '' || strcasecmp(trim($value), 'N/A') === 0)) {
                $request->merge([$field => null]);
            }
        }
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,company_id'],
            'branch_id' => ['required', 'integer', 'min:0'],
            'year' => ['required', 'integer', 'min:2023', 'max:'.now()->year],
            'quarter' => ['nullable','integer','between:1,3'],
            'vat_mode' => ['nullable','in:vat_inclusive,non_vat'],
            'tax_rate_type' => ['nullable','in:graduated,8_percent'],
            'deduction_method' => ['nullable','in:itemized'],
            'taxpayer_scope_confirmed' => ['nullable','boolean'],
            'taxable_compensation' => ['nullable','numeric','min:0','max:999999999999.99'],
            'income_type' => ['required_if:tax_rate_type,8_percent','nullable','in:pure_business,mixed_income'],
            'eight_percent_eligible' => ['required_if:tax_rate_type,8_percent','accepted_if:tax_rate_type,8_percent'],
            'other_business_income' => ['required_if:tax_rate_type,8_percent','nullable','numeric','min:0','max:999999999999.99'],
            'tax_credits' => ['nullable','numeric','min:0','max:999999999999.99'],
            'deductions' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'tax_rate_percent' => ['prohibited'],
            'surcharge' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'interest' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'compromise' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
        ]);
        $validated['_adjustment_states']=$adjustmentStates;

        $today = Carbon::today();
        $selectedYear = (int) $validated['year'];
        $currentYear = (int) $today->format('Y');

        $quarter=(int)($validated['quarter']??4);
        $yearStart=Carbon::create($selectedYear,1,1)->startOfDay();
        $yearEnd=Carbon::create($selectedYear,$quarter*3,1)->endOfMonth()->endOfDay();
        if($yearEnd->gte($today))return response()->json(['message'=>'Select a completed year or quarter.'],422);
        $all=(int)$validated['branch_id']===0;
        if($all && !\App\Services\v1\RoleAccess::managesCompany($request->user()->role))abort(403);
        $branches=Branch::with('company')->where('company_id',$validated['company_id'])->when(!$all,fn($q)=>$q->where('branch_id',$validated['branch_id']))->get();
        if($branches->isEmpty())return response()->json(['message'=>'No branches available in this scope.'],422);
        $branch=$branches->first();$branchIds=$branches->pluck('branch_id')->all();
        $vatMode=$validated['vat_mode']??'vat_inclusive';
        $taxRateType=$validated['tax_rate_type']??'graduated';
        
        $financials=\App\Services\v1\BranchTaxSummary::totals($branchIds,$selectedYear,$quarter,$vatMode==='vat_inclusive');
        $transactionQuery=Transaction::whereIn('branch_id',$branchIds)->where('status','completed')->whereBetween('created_at',[$yearStart,$yearEnd]);
        $transactionCount=(clone $transactionQuery)->count();
        $unitemized=(clone $transactionQuery)->whereNotExists(fn($q)=>$q->selectRaw('1')->from('transaction_items')->whereColumn('transaction_items.transaction_id','transactions.transaction_id'))->count();
        $grossSales=$financials['gross_sales'];$salesDiscounts=$financials['discounts'];
        $netSales=$financials['net_sales_revenue'];$costOfSales=$financials['cost_of_sales'];
        
        if($taxRateType === '8_percent' && $vatMode === 'vat_inclusive') return response()->json(['message'=>'The 8% flat rate is only applicable to Non-VAT taxpayers.'],422);
        $otherBusinessIncome = round((float) ($validated['other_business_income'] ?? 0), 2);
        if ($taxRateType === '8_percent') {
            if (!$all) return response()->json(['message'=>'Use All operating branches for an 8% taxpayer-wide reference. A single branch cannot establish taxpayer eligibility.'],422);
            $hasExcludedSales = Transaction::where('status','completed')->whereBetween('created_at',[$yearStart,$yearEnd])
                ->whereIn('branch_id',Branch::where('company_id',$validated['company_id'])->whereNotIn('branch_id',$branchIds)->select('branch_id'))->exists();
            if ($hasExcludedSales || $unitemized > 0) return response()->json(['message'=>'The 8% reference requires complete taxpayer sales. Inactive branches or transactions without item details are excluded from this scope. Reconcile those records first.'],422);
            if ($netSales + $otherBusinessIncome > 3000000) return response()->json(['message'=>'Combined sales and other business/non-operating income exceed the PHP 3,000,000 limit for this 8% reference.'],422);
            if ((float) ($validated['deductions'] ?? 0) > 0) return response()->json(['message'=>'Itemized deductions do not apply to the 8% method. Clear deductions to continue.'],422);
        }

        $grossIncome=round($netSales-$costOfSales,2);
        $deductions=round((float)($validated['deductions']??0),2);
        $taxableCompensation = $quarter===4 && ($validated['income_type']??'')==='mixed_income' ? round((float)($validated['taxable_compensation']??0),2) : 0;
        $businessNetIncome=round($grossIncome+$otherBusinessIncome-$deductions,2);
        $taxableNetIncome=round(max(0,$businessNetIncome)+$taxableCompensation,2);
        
        if ($taxRateType === '8_percent') {
            $reduction = $validated['income_type'] === 'pure_business' ? 250000 : 0;
            $taxableBasis = round(max(0, $netSales + $otherBusinessIncome - $reduction), 2);
            $taxableNetIncome = $taxableBasis;
            $incomeTaxDue = round($taxableBasis * 0.08, 2);
            $graduated = [
                'table_name' => '8% Flat Income Tax Rate',
                'floor' => $reduction,
                'base_tax' => 0,
                'marginal_rate' => 0.08,
                'excess' => $taxableBasis,
                'tax_due' => $incomeTaxDue
            ];
            $incomeTaxRate = 0.08;
        } else {
            $graduated=\App\Services\v1\BranchTaxSummary::graduated($taxableNetIncome);
            $graduated['table_name'] = 'Graduated tax - Table 2 (2023+)';
            $incomeTaxDue=$graduated['tax_due'];
            $incomeTaxRate=$graduated['marginal_rate'];
        }
        
        $taxCredits=round((float)($validated['tax_credits']??0),2);
        $basicTaxPayment = $incomeTaxDue;
        $surcharge = round((float) ($validated['surcharge'] ?? 0), 2);
        $interest = round((float) ($validated['interest'] ?? 0), 2);
        $compromise = round((float) ($validated['compromise'] ?? 0), 2);
        $totalAmountPayable = round($basicTaxPayment - $taxCredits + $surcharge + $interest + $compromise, 2);
        $returnPeriod = $yearEnd->toDateString();
        $dueDate = null; // Filing deadlines/extensions must be checked against the applicable BIR calendar.
        $registeredAddress = $all ? '' : trim((string) ($branch->branch_address ?? ''));
        $telephoneNumber = $all ? '' : trim((string) ($branch->branch_contact ?? ''));
        $taxpayerName = (string)($branch->company?->company_name ?? 'Not supplied');
        $lineOfBusiness = 'Retail Pharmacy / Drugstore Operations';
        $withholdingQuery = Bir2306Record::query()
            ->where('company_id', $validated['company_id'])
            ->where('branch_id', $all ? -1 : $branch->branch_id)
            ->whereDate('period_from', '>=', $yearStart->toDateString())
            ->whereDate('period_to', '<=', $yearEnd->toDateString());
        if ((clone $withholdingQuery)->where('is_test_data', false)->exists()) {
            $withholdingQuery->where('is_test_data', false);
        }
        $withholdingRecords = $withholdingQuery
            ->orderBy('period_from')
            ->get();
        $firstWithholdingRecord = $withholdingRecords->first();
        $incomePayments = $withholdingRecords->map(fn (Bir2306Record $record) => [
            'nature_of_income_payment' => $record->nature_of_income_payment,
            'atc' => $record->atc,
            'amount_of_payment' => (float) $record->amount_of_payment,
            'tax_withheld' => (float) $record->tax_withheld,
            'period_from' => $record->period_from?->toDateString(),
            'period_to' => $record->period_to?->toDateString(),
            'source_reference' => $record->source_reference,
            'is_test_data' => (bool) $record->is_test_data,
        ])->values();
        $totalIncomePayment = round((float) $withholdingRecords->sum('amount_of_payment'), 2);
        $totalTaxWithheld = round((float) $withholdingRecords->sum('tax_withheld'), 2);
        $form2306MissingFields = collect([
            empty($branch->company?->tin_number) ? 'Payee TIN' : null,
            empty($branch->company?->company_name) ? 'Payee registered name' : null,
            empty($registeredAddress) ? 'Payee registered address' : null,
            empty($branch->zip_code) ? 'Payee ZIP code' : null,
            $withholdingRecords->isEmpty() ? 'At least one actual withholding-agent income payment record' : null,
            $withholdingRecords->contains(fn (Bir2306Record $record) => $record->is_test_data)
                ? 'Test withholding records must be replaced with actual certificates before issuance' : null,
            $firstWithholdingRecord && empty($firstWithholdingRecord->payor_signatory_name)
                ? 'Payor authorized representative/signatory' : null,
            $firstWithholdingRecord && empty($firstWithholdingRecord->payee_signatory_name)
                ? 'Payee authorized representative/signatory' : null,
        ])->filter()->values();

        $referenceData = [
                'form_no' => $quarter===4 ? ($taxRateType === '8_percent' && $validated['income_type'] === 'pure_business' ? '1701A' : '1701') : '1701Q',
                'tax_rate_type' => $taxRateType,
                'income_type' => $validated['income_type'] ?? null,
                'other_business_income' => $otherBusinessIncome,
                'taxable_compensation' => $taxableCompensation,
                'business_net_income' => $businessNetIncome,
                'deduction_method' => 'itemized',
                'generated_at' => now(),
                'branch_id' => $all ? 0 : $branch->branch_id,
                'branch_name' => $all ? 'Company-wide (including inactive branches)' : $branch->branch_name,
                'company_id' => $branch->company?->company_id,
                'taxpayer_name' => $taxpayerName,
                'tin_number' => $branch->company?->tin_number,
                'taxable_year' => $selectedYear,
                'quarter' => $quarter===4 ? null : $quarter,
                'period_from' => $yearStart->toDateString(),
                'period_to' => $yearEnd->toDateString(),
                'vat_mode' => $vatMode,
                'vat' => $financials,
                'graduated_tax' => $graduated,
                'tax_credits' => $taxCredits,
                'scope_type' => $all ? 'company' : 'branch',
                'unitemized_transactions' => $unitemized,
                'is_provisional' => $financials['unverified_lines']>0 || $unitemized>0,
                'reference_only' => true,
                'return_period' => $returnPeriod,
                'due_date' => $dueDate,
                'tax_type_code' => 'IT',
                'tax_type_description' => 'Income Tax',
                'atc' => empty($validated['income_type']) ? null : ($taxRateType==='8_percent' ? (($validated['income_type']==='mixed_income')?'II016':'II015') : (($validated['income_type']==='mixed_income')?'II013':'II012')),
                'atc_description' => 'Business-income ATC reference for selected income type and tax method',
                'manner_of_payment' => 'Voluntary Payment',
                'type_of_payment' => 'Branch Tax Payment Summary',
                'line_of_business' => $lineOfBusiness,
                'registered_address' => $registeredAddress,
                'telephone_number' => $telephoneNumber,
                'computations' => [
                    'net_sales' => $netSales,
                    'cost_of_sales' => $costOfSales,
                    'gross_income' => $grossIncome,
                    'itemized_deductions' => $deductions,
                    'taxable_net_income' => $taxableNetIncome,
                    'tax_due' => $incomeTaxDue,
                    'total_amount_payable' => $totalAmountPayable,
                ],
                'form_2306' => [
                    'form_name' => 'Certificate of Final Tax Withheld at Source',
                    'form_revision' => 'January 2018 (ENCS)',
                    'period_from' => $selectedYear . '-01-01',
                    'period_to' => $selectedYear . '-12-31',
                    'payee' => [
                        'tin' => $branch->company?->tin_number,
                        'registered_name' => $branch->company?->company_name,
                        'registered_address' => $registeredAddress,
                        'zip_code' => $branch->zip_code,
                        'foreign_address' => null,
                    ],
                    'withholding_agent' => [
                        'tin' => $firstWithholdingRecord?->payor_tin,
                        'registered_name' => $firstWithholdingRecord?->payor_registered_name,
                        'registered_address' => $firstWithholdingRecord?->payor_registered_address,
                        'zip_code' => $firstWithholdingRecord?->payor_zip_code,
                    ],
                    'income_payments' => $incomePayments,
                    'total_income_payment' => $totalIncomePayment,
                    'total_tax_withheld' => $totalTaxWithheld,
                    'payor_signatory' => $firstWithholdingRecord ? [
                        'name' => $firstWithholdingRecord->payor_signatory_name,
                        'tin' => $firstWithholdingRecord->payor_signatory_tin,
                        'title' => $firstWithholdingRecord->payor_signatory_title,
                        'certificate_date' => $firstWithholdingRecord->certificate_date?->toDateString(),
                    ] : null,
                    'is_ready_to_issue' => $form2306MissingFields->isEmpty(),
                    'missing_fields' => $form2306MissingFields,
                    'compliance_note' => 'Internal branch tax payment summary. Amounts may include system-calculated references; confirm against source documents before using them on a BIR form.',
                    'part_i_payee' => [
                        'tin' => $branch->company?->tin_number,
                        'name' => $branch->company?->company_name,
                        'registered_address' => $registeredAddress,
                        'zip_code' => $branch->zip_code,
                        'foreign_address' => $firstWithholdingRecord?->payee_foreign_address,
                        'icr_no' => $firstWithholdingRecord?->payee_icr_no,
                    ],
                    'part_ii_payor' => [
                        'tin' => $firstWithholdingRecord?->payor_tin,
                        'name' => $firstWithholdingRecord?->payor_registered_name,
                        'registered_address' => $firstWithholdingRecord?->payor_registered_address,
                        'zip_code' => $firstWithholdingRecord?->payor_zip_code,
                    ],
                    'part_iii_income_payment_and_tax_withheld' => [
                        'rows' => $incomePayments,
                        'total_amount_of_payment' => $totalIncomePayment,
                        'total_tax_withheld' => $totalTaxWithheld,
                    ],
                    'payor_declaration' => $firstWithholdingRecord ? [
                        'signature_over_printed_name' => $firstWithholdingRecord->payor_signatory_name,
                        'title_designation' => $firstWithholdingRecord->payor_signatory_title,
                        'tin' => $firstWithholdingRecord->payor_signatory_tin,
                        'date_signed' => $firstWithholdingRecord->certificate_date?->toDateString(),
                        'tax_agent_accreditation_no' => $firstWithholdingRecord->payor_tax_agent_accreditation_no,
                        'date_of_issue' => $firstWithholdingRecord->payor_accreditation_date_issued?->toDateString(),
                        'date_of_expiry' => $firstWithholdingRecord->payor_accreditation_date_expiry?->toDateString(),
                        'attorney_roll_no' => $firstWithholdingRecord->payor_attorney_roll_no,
                    ] : null,
                    'payee_conforme' => $firstWithholdingRecord ? [
                        'signature_over_printed_name' => $firstWithholdingRecord->payee_signatory_name,
                        'title_designation' => $firstWithholdingRecord->payee_signatory_title,
                        'tin' => $firstWithholdingRecord->payee_signatory_tin,
                        'date_signed' => $firstWithholdingRecord->payee_date_signed?->toDateString(),
                        'tax_agent_accreditation_no' => $firstWithholdingRecord->payee_tax_agent_accreditation_no,
                        'date_of_issue' => $firstWithholdingRecord->payee_accreditation_date_issued?->toDateString(),
                        'date_of_expiry' => $firstWithholdingRecord->payee_accreditation_date_expiry?->toDateString(),
                        'attorney_roll_no' => $firstWithholdingRecord->payee_attorney_roll_no,
                    ] : null,
                    'substituted_filing' => $firstWithholdingRecord ? [
                        'applicable' => (bool) $firstWithholdingRecord->substituted_filing_applicable,
                        'payor_declaration' => [
                            'signature_over_printed_name' => $firstWithholdingRecord->substituted_payor_signatory_name,
                            'title_designation' => $firstWithholdingRecord->substituted_payor_signatory_title,
                            'tin' => $firstWithholdingRecord->substituted_payor_signatory_tin,
                            'date_signed' => $firstWithholdingRecord->substituted_payor_date_signed?->toDateString(),
                        ],
                        'payee_declaration' => [
                            'signature_over_printed_name' => $firstWithholdingRecord->substituted_payee_signatory_name,
                            'title_designation' => $firstWithholdingRecord->substituted_payee_signatory_title,
                            'tin' => $firstWithholdingRecord->substituted_payee_signatory_tin,
                            'date_signed' => $firstWithholdingRecord->substituted_payee_date_signed?->toDateString(),
                        ],
                    ] : null,
                ],
                'basic_tax_payment' => $basicTaxPayment,
                'surcharge' => $surcharge,
                'interest' => $interest,
                'compromise' => $compromise,
                'total_amount_payable' => $totalAmountPayable,
                'transaction_count' => $transactionCount,
                'gross_sales_receipts' => $grossSales,
                'sales_discounts' => $salesDiscounts,
                'net_sales_receipts' => $netSales,
                'cost_of_sales' => $costOfSales,
                'gross_income' => $grossIncome,
                'deductions' => $deductions,
                'taxable_net_income' => $taxableNetIncome,
                'income_tax_rate' => $incomeTaxRate,
                'income_tax_due' => $incomeTaxDue,
                'computation' => [
                    'gross_sales_receipts' => $grossSales,
                    'less_sales_discounts' => $salesDiscounts,
                    'net_sales_receipts' => $netSales,
                    'less_cost_of_sales' => $costOfSales,
                    'gross_income' => $grossIncome,
                    'less_deductions' => $deductions,
                    'taxable_net_income' => $taxableNetIncome,
                    'income_tax_rate' => $incomeTaxRate,
                    'income_tax_due' => $incomeTaxDue,
                    'basic_tax_payment' => $basicTaxPayment,
                    'surcharge' => $surcharge,
                    'interest' => $interest,
                    'compromise' => $compromise,
                    'total_amount_payable' => $totalAmountPayable,
                    'formula_notes' => [
                        'Net sales receipts = discounted item receipts - extracted output VAT.',
                        'Gross income = net sales receipts - cost of sales.',
                        $taxRateType === '8_percent' ? '8% taxable base = max(0, sales + entered other business/non-operating income - allowed reduction). Costs and itemized deductions do not apply.' : 'Taxable net income = max(0, gross income - deductions).',
                        $taxRateType === '8_percent' ? 'Income tax due = 8% x taxable sales/income base; PHP 250,000 reduction only for purely business/professional income.' : 'Income tax due = bracket base tax + marginal rate x excess above the bracket floor.',
                        'Payable reference = income tax due - entered credits + surcharge + interest + compromise.',
                    ],
                ],
                'is_ready_to_file' => false,
                'data_sources' => [
                    ['label' => 'Gross Sales', 'source' => 'Completed POS item quantity x historical selling price for the authorized scope and period.'],
                    ['label' => 'Sales Discounts', 'source' => 'Recorded item gross minus actual completed transaction receipts, allocated proportionally.'],
                    ['label' => 'Cost of Sales', 'source' => 'Quantity dispensed multiplied by the batch cost snapshot saved in each transaction item.'],
                    ['label' => 'Gross Income', 'source' => 'Calculated as net sales receipts minus cost of sales.'],
                    ['label' => 'Deductions', 'source' => isset($validated['deductions']) ? 'Manually entered for this report.' : 'Not supplied / N/A. Uses 0 for this report.'],
                    ['label' => 'Income Tax Rate', 'source' => $taxRateType === '8_percent' ? '8% of sales plus entered other business/non-operating income, less PHP 250,000 only for purely business/professional income.' : '2023+ individual graduated tax table; marginal rate applied only to income above the bracket floor.'],
                    ...collect(['surcharge' => 'Surcharge', 'interest' => 'Interest', 'compromise' => 'Compromise'])->map(fn ($label, $field) => ['label' => $label, 'source' => isset($validated[$field]) ? 'Manually entered for this report; not generated from assessment records.' : 'Not supplied / N/A. Uses 0 for this report.'])->values()->all(),
                ],
                'data_notes' => [
                    'Internal individual income-tax reference, not a filed return or corporate income-tax computation. Taxpayer registration and eligibility must be confirmed.',
                    $taxRateType === '8_percent' ? '8% eligibility and election are user-confirmed. Mixed-income earners receive no PHP 250,000 reduction; compensation tax is excluded. Costs and itemized deductions do not reduce the 8% base. Recheck taxpayer-wide annual eligibility if income exceeds PHP 3 million.' : 'The graduated method uses taxable business income after entered itemized deductions.',
                    $all ? 'Company-wide scope includes historical activity from inactive branches. Tax is estimated once on the combined taxpayer income.' : 'Branch financial reference only; no separate branch income-tax liability is calculated.',
                    'Quarterly calculations use cumulative January-to-quarter-end amounts. Deductions and tax credits entered must cover that same cumulative period.',
                    'Only completed transactions are included. New sales use saved line amounts and VAT after item-level exemptions and discounts; older records use proportional allocation. Current medicine prices are never used.',
                    $financials['unverified_lines'].' item lines lack historical tax classification or cost evidence. Unknown VAT or costs are shown as not calculated, never as a verified zero.',
                    $unitemized.' completed transactions have no item rows and are excluded from item-derived totals.',
                    'Input VAT is a reference from recorded cost treatment, not a claim of entitlement to an input-tax credit. Exempt-sale costs retain their full recorded value.',
                    'Maintenance categories do not establish VAT exemption. Use verified tax classification and invoice evidence.',
                    'Itemized expenses must be supported and must exclude cost of sales, recoverable input VAT, and discounts already reflected in net sales. Do not deduct the same amount twice.',
                    'Blank adjustments mean not entered; N/A means not applicable; zero means an entered zero. Omitted adjustments are excluded from this estimate and remain identified in the output.',
                    'Tax credits/prior payments require supporting records and must cover the selected cumulative period. A negative balance is a reference excess credit, not an automatic refund.',
                    'Tax payable reference subtracts entered credits/payments; a negative balance indicates an overpayment reference. Only explicitly entered other business income is included in 8% mode; compensation tax and other filing adjustments remain outside this summary.',
                ],
        ];
        return response()->json(['success'=>true,'data'=>\App\Services\v1\TaxReferenceReadiness::apply($referenceData,$validated,$profile)]);
    }

    private function percentChange(float $current, float $previous): float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100 : 0;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }

    private function buildAnalysis(array $summary, $paymentMix, $branchPerformance, $topMedicines): array
    {
        $headline = 'Report analytics are ready for the selected operating window.';
        $highlights = [];

        $revenueChange = $this->percentChange($summary['current_revenue'], $summary['previous_revenue']);
        if ($revenueChange > 0) {
            $highlights[] = 'Revenue is up by ' . $revenueChange . '% versus the previous comparable period.';
        } elseif ($revenueChange < 0) {
            $highlights[] = 'Revenue is down by ' . abs($revenueChange) . '% versus the previous comparable period.';
        } else {
            $highlights[] = 'Revenue stayed flat compared with the previous comparable period.';
        }

        if ($paymentMix->isNotEmpty()) {
            $topPayment = $paymentMix->first();
            $highlights[] = $topPayment['payment_method'] . ' is the leading payment channel with ' . $topPayment['transaction_count'] . ' transactions.';
        }

        if ($branchPerformance->isNotEmpty()) {
            $topBranch = $branchPerformance->first();
            $highlights[] = $topBranch['branch_name'] . ' generated the highest revenue in the current reporting window.';
        }

        if ($topMedicines->isNotEmpty()) {
            $topMedicine = $topMedicines->first();
            $highlights[] = $topMedicine['medicine_name'] . ' is the top-selling medicine with ' . $topMedicine['quantity_sold'] . ' units sold.';
        }

        if ($summary['low_stock_count'] > 0) {
            $headline = 'Sales are active, but inventory follow-up is needed.';
            $highlights[] = $summary['low_stock_count'] . ' inventory items are already at or below reorder level.';
        }

        if ($summary['expiring_30_count'] > 0) {
            $highlights[] = $summary['expiring_30_count'] . ' inventory batches are expiring within 30 days and should be monitored in the next replenishment cycle.';
        }

        return [
            'headline' => $headline,
            'highlights' => array_values(array_unique($highlights)),
        ];
    }

    private function mapRegulatedTransaction(Transaction $transaction): array
    {
        return [
            'transaction_id' => $transaction->transaction_id,
            'regulated_classification' => $transaction->regulated_classification,
            'branch_name' => $transaction->branch?->branch_name,
            'cashier_name' => trim(($transaction->user?->first_name ?? '') . ' ' . ($transaction->user?->last_name ?? '')),
            'payment_method' => $transaction->payment_method,
            'reference_number' => $transaction->reference_number,
            'total_amount' => (float) $transaction->total_amount,
            'discount' => (float) ($transaction->discount ?? 0),
            'patient_name' => $transaction->patient_name,
            'created_at' => $transaction->created_at,
            'regulated_details' => $transaction->regulated_details,
        ];
    }
}
