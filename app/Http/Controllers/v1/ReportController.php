<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\v1\Batch;
use App\Models\v1\Bir2306Record;
use App\Models\v1\Branch;
use App\Models\v1\InventoryTransfer;
use App\Models\v1\Medicine;
use App\Models\v1\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
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
            'payor_signatory_title' => ['required', 'string', 'max:255'],
            'payor_signatory_tin' => ['required', 'string', 'max:30'],
            'certificate_date' => ['required', 'date'],
            'payee_signatory_name' => ['required', 'string', 'max:255'],
            'payee_signatory_title' => ['required', 'string', 'max:255'],
            'payee_signatory_tin' => ['required', 'string', 'max:30'],
            'payee_date_signed' => ['required', 'date'],
            'payor_tax_agent_accreditation_no' => ['nullable', 'string', 'max:100'],
            'payor_accreditation_date_issued' => ['nullable', 'date'],
            'payor_accreditation_date_expiry' => ['nullable', 'date'],
            'payor_attorney_roll_no' => ['nullable', 'string', 'max:100'],
            'payee_tax_agent_accreditation_no' => ['nullable', 'string', 'max:100'],
            'payee_accreditation_date_issued' => ['nullable', 'date'],
            'payee_accreditation_date_expiry' => ['nullable', 'date'],
            'payee_attorney_roll_no' => ['nullable', 'string', 'max:100'],
            'substituted_filing_applicable' => ['nullable', 'boolean'],
            'substituted_payor_signatory_name' => ['required_if:substituted_filing_applicable,true', 'nullable', 'string', 'max:255'],
            'substituted_payor_signatory_tin' => ['required_if:substituted_filing_applicable,true', 'nullable', 'string', 'max:30'],
            'substituted_payor_signatory_title' => ['required_if:substituted_filing_applicable,true', 'nullable', 'string', 'max:255'],
            'substituted_payor_date_signed' => ['required_if:substituted_filing_applicable,true', 'nullable', 'date'],
            'substituted_payee_signatory_name' => ['required_if:substituted_filing_applicable,true', 'nullable', 'string', 'max:255'],
            'substituted_payee_signatory_tin' => ['required_if:substituted_filing_applicable,true', 'nullable', 'string', 'max:30'],
            'substituted_payee_signatory_title' => ['required_if:substituted_filing_applicable,true', 'nullable', 'string', 'max:255'],
            'substituted_payee_date_signed' => ['required_if:substituted_filing_applicable,true', 'nullable', 'date'],
        ]);

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

        if ($scopeBranchIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [
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
                        'prescribed_transactions' => [],
                        'dangerous_transactions' => [],
                        'stock_transfers' => [],
                    ],
                    'analysis' => [
                        'headline' => 'No report data is available for the selected scope yet.',
                        'highlights' => [],
                    ],
                ],
            ]);
        }

        $allTransactions = Transaction::query()
<<<<<<< HEAD

            ->where(fn ($q) => $q->whereNull('transactions.status')->orWhere('transactions.status', '<>', 'voided'))
            ->whereIn('branch_id', $scopeBranchIds);
=======
            ->whereIn('branch_id', $scopeBranchIds)
            ->where('status', '!=', 'voided');
>>>>>>> f828ce2 (Add BIR 2306 records, SMS orders, batch history, inventory revisions)

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
                    ->orWhereNotIn('batches.status', ['archived', 'pulled_out', 'disposed', 'deleted']);
            })
            ->first();

        $inventoryValue = (float) ($inventorySummary->inventory_value ?? 0);
        $lowStockCount = (int) ($inventorySummary->low_stock_count ?? 0);

        $batchSummary = Batch::query()
            ->join('inventories', 'inventories.batch_id', '=', 'batches.batch_id')
            ->whereIn('inventories.branch_id', $scopeBranchIds)
            ->where(function ($statusQuery) {
                $statusQuery->whereNull('batches.status')
                    ->orWhereNotIn('batches.status', ['archived', 'pulled_out', 'disposed', 'deleted']);
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
            ->where(fn ($q) => $q->whereNull('transactions.status')->orWhere('transactions.status', '<>', 'voided'))
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
<<<<<<< HEAD
                    ->where(fn ($q) => $q->whereNull('transactions.status')->orWhere('transactions.status', '<>', 'voided'))
=======
                    ->where('transactions.status', '!=', 'voided')
>>>>>>> f828ce2 (Add BIR 2306 records, SMS orders, batch history, inventory revisions)
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
            ->where(fn ($q) => $q->whereNull('transactions.status')->orWhere('transactions.status', '<>', 'voided'))
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

        $inventoryWatch = DB::table('medicines')
            ->join('inventories', 'medicines.medicine_id', '=', 'inventories.medicine_id')
            ->join('branches', 'inventories.branch_id', '=', 'branches.branch_id')
            ->leftJoin('batches', 'inventories.batch_id', '=', 'batches.batch_id')
            ->selectRaw('
                medicines.medicine_id,
                medicines.medicine_name,
                medicines.generic_name,
                inventories.stocks,
                medicines.reorder_level,
                medicines.price,
                branches.branch_name,
                batches.expiry_date,
                inventories.created_at
            ')
            ->whereIn('inventories.branch_id', $scopeBranchIds)
            ->whereBetween('inventories.created_at', [$rangeStart, $rangeEnd])
            ->where(function ($statusQuery) {
                $statusQuery->whereNull('batches.status')
                    ->orWhereNotIn('batches.status', ['archived', 'pulled_out', 'disposed', 'deleted']);
            })
            ->orderByRaw('CASE WHEN inventories.stocks <= medicines.reorder_level THEN 0 ELSE 1 END')
            ->orderBy('batches.expiry_date')
            ->limit(10)
            ->get()
            ->map(function ($row) use ($today) {
                $status = 'Healthy';
                if ((int) $row->stocks <= 0) {
                    $status = 'Out of Stock';
                } elseif ((int) $row->stocks <= (int) $row->reorder_level) {
                    $status = 'Low Stock';
                } elseif (!empty($row->expiry_date) && Carbon::parse($row->expiry_date)->lt($today->copy()->addDays(30))) {
                    $status = 'Expiring Soon';
                }

                return [
                    'medicine_id' => $row->medicine_id,
                    'medicine_name' => $row->medicine_name,
                    'generic_name' => $row->generic_name,
                    'branch_name' => $row->branch_name,
                    'stocks' => (int) $row->stocks,
                    'reorder_level' => (int) $row->reorder_level,
                    'price' => (float) $row->price,
                    'expiry_date' => $row->expiry_date,
                    'created_at' => $row->created_at,
                    'status' => $status,
                ];
            })
            ->values();

        $inventoryStatusSnapshot = DB::table('medicines')
            ->join('inventories', 'medicines.medicine_id', '=', 'inventories.medicine_id')
            ->leftJoin('batches', 'inventories.batch_id', '=', 'batches.batch_id')
            ->whereIn('inventories.branch_id', $scopeBranchIds)
            ->where(function ($statusQuery) {
                $statusQuery->whereNull('batches.status')
                    ->orWhereNotIn('batches.status', ['archived', 'pulled_out', 'disposed', 'deleted']);
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
<<<<<<< HEAD
            ->with(['user:user_id,first_name,last_name', 'branch:branch_id,branch_name', 'attachments'])
=======
            ->with(['user:user_id,first_name,last_name', 'branch:branch_id,branch_name', 'items:transaction_item_id,transaction_id,batch_number,batch_id'])
>>>>>>> f828ce2 (Add BIR 2306 records, SMS orders, batch history, inventory revisions)
            ->whereIn('branch_id', $scopeBranchIds)
            ->where('status', '!=', 'voided')
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($transaction) => [
                'transaction_id' => $transaction->transaction_id,
                'status' => $transaction->status ?? 'completed',
                'branch_name' => $transaction->branch?->branch_name,
                'cashier_name' => trim(($transaction->user?->first_name ?? '') . ' ' . ($transaction->user?->last_name ?? '')),
                'payment_method' => $transaction->payment_method,
                'reference_number' => $transaction->reference_number,
                'transaction_type' => $transaction->transaction_type,
                'regulated_classification' => $transaction->regulated_classification,
                'patient_name' => $transaction->patient_name,
                'total_amount' => (float) $transaction->total_amount,
                'discount' => (float) ($transaction->discount ?? 0),
                'batch_numbers' => $transaction->items->pluck('batch_number')->filter()->unique()->values()->all(),
                'created_at' => $transaction->created_at,
            ])
            ->values();

        $prescribedTransactions = Transaction::query()
            ->with(['user:user_id,first_name,last_name', 'branch:branch_id,branch_name', 'attachments'])
            ->whereIn('branch_id', $scopeBranchIds)
            ->where('status', '!=', 'voided')
            ->whereIn('regulated_classification', ['controlled', 'mixed'])
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($transaction) => $this->mapRegulatedTransaction($transaction))
            ->values();

        $stockTransfers = InventoryTransfer::query()
            ->with([
                'medicine:medicine_id,medicine_name,generic_name',
                'batch:batch_id,batch_number,expiry_date,mfg_date',
                'fromBranch:branch_id,branch_name',
                'toBranch:branch_id,branch_name',
                'requester:user_id,first_name,last_name',
                'resolver:user_id,first_name,last_name',
            ])
            ->where(function ($query) use ($scopeBranchIds) {
                $query->whereIn('from_branch_id', $scopeBranchIds)
                    ->orWhereIn('to_branch_id', $scopeBranchIds);
            })
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->latest('created_at')
            ->limit(20)
            ->get()
            ->map(fn (InventoryTransfer $transfer) => [
                'inventory_transfer_id' => $transfer->inventory_transfer_id,
                'medicine_name' => $transfer->medicine?->medicine_name,
                'generic_name' => $transfer->medicine?->generic_name,
                'batch_number' => $transfer->batch?->batch_number ?? $transfer->batch_id,
                'expiry_date' => $transfer->batch?->expiry_date,
                'mfg_date' => $transfer->batch?->mfg_date,
                'from_branch_name' => $transfer->fromBranch?->branch_name,
                'to_branch_name' => $transfer->toBranch?->branch_name,
                'quantity' => (int) $transfer->quantity,
                'status' => $transfer->status,
                'requested_by' => trim(($transfer->requester?->first_name ?? '') . ' ' . ($transfer->requester?->last_name ?? '')),
                'resolved_by' => trim(($transfer->resolver?->first_name ?? '') . ' ' . ($transfer->resolver?->last_name ?? '')) ?: null,
                'created_at' => $transfer->created_at,
                'resolved_at' => $transfer->resolved_at,
            ])
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

        return response()->json([
            'success' => true,
            'data' => [
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
                    'prescribed_transactions' => $prescribedTransactions,
                    'dangerous_transactions' => $dangerousTransactions,
                    'stock_transfers' => $stockTransfers,
                ],
                'analysis' => $analysis,
            ],
        ]);
    }

    public function birAnnualDeclaration(Request $request)
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,company_id'],
            'branch_id' => ['required', 'integer', 'exists:branches,branch_id'],
            'year' => ['required', 'integer', 'min:2000'],
        ]);

        $today = Carbon::today();
        $selectedYear = (int) $validated['year'];
        $currentYear = (int) $today->format('Y');

        if ($selectedYear >= $currentYear) {
            return response()->json([
                'success' => false,
<<<<<<< HEAD
                'message' => 'BIR 2306 summaries can only be generated for a completed taxable year.',
=======
                'message' => 'Branch Tax Payment Summary reports can only be generated for a completed taxable year.',
>>>>>>> f828ce2 (Add BIR 2306 records, SMS orders, batch history, inventory revisions)
            ], 422);
        }

        $branch = Branch::query()
            ->with('company')
            ->where('status', 'active')
            ->where('company_id', $validated['company_id'])
            ->where('branch_id', $validated['branch_id'])
            ->first();

        if (!$branch) {
            return response()->json([
                'success' => false,
                'message' => 'Selected branch is not available for the specified company.',
            ], 422);
        }

        $yearStart = Carbon::create($selectedYear, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($selectedYear, 12, 31)->endOfDay();

        $transactionSummary = Transaction::query()

            ->where(fn ($q) => $q->whereNull('transactions.status')->orWhere('transactions.status', '<>', 'voided'))
            ->where('branch_id', $branch->branch_id)
            ->where('status', '!=', 'voided')
            ->whereBetween('created_at', [$yearStart, $yearEnd])
            ->selectRaw('
                COALESCE(SUM(sub_total), 0) as gross_sales,
                COALESCE(SUM(discount), 0) as sales_discounts,
                COALESCE(SUM(total_amount), 0) as net_receipts,
                COUNT(*) as transaction_count
            ')
            ->first();

        $grossSales = round((float) ($transactionSummary->gross_sales ?? 0), 2);
        $salesDiscounts = round((float) ($transactionSummary->sales_discounts ?? 0), 2);
        $netSales = round(max($grossSales - $salesDiscounts, 0), 2);
        $costOfSales = round((float) DB::table('transaction_items')
            ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.transaction_id')
            ->where('transactions.branch_id', $branch->branch_id)
            ->where('transactions.status', '!=', 'voided')
            ->whereBetween('transactions.created_at', [$yearStart, $yearEnd])
            ->selectRaw('COALESCE(SUM(transaction_items.quantity * transaction_items.cost_price), 0) as total_cost')
            ->value('total_cost'), 2);
        $grossIncome = round($netSales - $costOfSales, 2);
        $deductions = 0.00;
        $taxableNetIncome = round(max($grossIncome - $deductions, 0), 2);
        $incomeTaxRate = 0.25;
        $incomeTaxDue = round($taxableNetIncome * $incomeTaxRate, 2);
        $basicTaxPayment = $incomeTaxDue;
        $surcharge = 0.00;
        $interest = 0.00;
        $compromise = 0.00;
        $totalAmountPayable = round($basicTaxPayment + $surcharge + $interest + $compromise, 2);
        $returnPeriod = Carbon::create($selectedYear, 12, 31)->toDateString();
        $dueDate = Carbon::create($selectedYear + 1, 4, 15)->toDateString();
        $registeredAddress = trim((string) ($branch->branch_address ?? ''));
        $telephoneNumber = trim((string) ($branch->branch_contact ?? ''));
        $taxpayerName = trim(($branch->company?->company_name ?? 'Pharmacy') . ' - ' . $branch->branch_name . ' Branch');
        $lineOfBusiness = 'Retail Pharmacy / Drugstore Operations';
        $withholdingQuery = Bir2306Record::query()
            ->where('company_id', $validated['company_id'])
            ->where('branch_id', $branch->branch_id)
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
            $firstWithholdingRecord && empty($firstWithholdingRecord->certificate_date)
                ? 'Payor date signed' : null,
            $firstWithholdingRecord && empty($firstWithholdingRecord->payee_date_signed)
                ? 'Payee date signed' : null,
        ])->filter()->values();

        return response()->json([
            'success' => true,
            'data' => [
                'form_no' => '2306',
                'generated_at' => now(),
                'branch_id' => $branch->branch_id,
                'branch_name' => $branch->branch_name,
                'company_id' => $branch->company?->company_id,
                'taxpayer_name' => $taxpayerName,
                'tin_number' => $branch->company?->tin_number,
                'taxable_year' => $selectedYear,
                'return_period' => $returnPeriod,
                'due_date' => $dueDate,
                'tax_type_code' => 'IT',
                'tax_type_description' => 'Income Tax',
                'atc' => 'MC 200',
                'atc_description' => 'Others',
                'manner_of_payment' => 'Voluntary Payment',
<<<<<<< HEAD
                'type_of_payment' => 'Others - Income tax payment summary via BIR Form 2306',
=======
                'type_of_payment' => 'Branch Tax Payment Summary',
>>>>>>> f828ce2 (Add BIR 2306 records, SMS orders, batch history, inventory revisions)
                'line_of_business' => $lineOfBusiness,
                'registered_address' => $registeredAddress,
                'telephone_number' => $telephoneNumber,
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
                    'compliance_note' => 'Form 2306 values come only from recorded final-tax withholding certificates. Ordinary POS sales are not treated as withholding records.',
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
                'transaction_count' => (int) ($transactionSummary->transaction_count ?? 0),
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
                        'Net sales receipts = gross sales receipts - sales discounts.',
                        'Gross income = net sales receipts - cost of sales.',
                        'Taxable net income = gross income - deductions.',
                        'Income tax due = taxable net income x income tax rate.',
                        'Total amount payable = basic tax payment + surcharge + interest + compromise.',
                    ],
                ],
                'is_ready_to_file' => false,
                'data_sources' => [
                    ['label' => 'Gross Sales', 'source' => 'Completed POS transactions for the selected branch and year; sum of transactions.sub_total.'],
                    ['label' => 'Sales Discounts', 'source' => 'Recorded senior, PWD, and other transaction discounts; sum of transactions.discount.'],
                    ['label' => 'Cost of Sales', 'source' => 'Quantity dispensed multiplied by the batch cost snapshot saved in each transaction item.'],
                    ['label' => 'Gross Income', 'source' => 'Calculated as net sales receipts minus cost of sales.'],
                    ['label' => 'Deductions', 'source' => 'Currently 0 because an operating-expenses and allowable-deductions ledger has not been implemented.'],
                    ['label' => '25% Tax Rate', 'source' => 'Reference assumption only; it is not selected from the pharmacy tax profile and must be confirmed by an accountant.'],
                    ['label' => 'Surcharge, Interest, and Compromise', 'source' => 'Currently 0 because no BIR assessment or penalty record was entered.'],
                    ['label' => 'Form 2306', 'source' => 'Explicit final-tax withholding records plus company and branch registration details; ordinary POS sales are excluded.'],
                ],
                'data_notes' => [
<<<<<<< HEAD
                    'This output follows the BIR Form 2306 payment-form layout using the currently available sales and tax summary data in the system.',
=======
                    'This output provides a branch tax payment summary using the currently available sales and tax summary data in the system.',
>>>>>>> f828ce2 (Add BIR 2306 records, SMS orders, batch history, inventory revisions)
                    'ATC, tax type code, due date, and payment classification should still be validated against the actual liability being paid before filing.',
                    'Basic tax payment is derived from the computed annual tax due in the current report, while surcharge, interest, and compromise are set to 0.00 unless manually assessed.',
                    'Please reconcile this branch tax payment summary with your accountant and official filing requirements before submission.',
                ],
            ],
        ]);
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
            'status' => $transaction->status ?? 'completed',
            'regulated_classification' => $transaction->regulated_classification,
            'branch_name' => $transaction->branch?->branch_name,
            'cashier_name' => trim(($transaction->user?->first_name ?? '') . ' ' . ($transaction->user?->last_name ?? '')),
            'payment_method' => $transaction->payment_method,
            'reference_number' => $transaction->reference_number,
            'total_amount' => (float) $transaction->total_amount,
            'discount' => (float) ($transaction->discount ?? 0),
            'patient_name' => $transaction->patient_name,
            'created_at' => $transaction->created_at,
            'prescription_url' => $transaction->prescription_url,
            'member_id_image_url' => $transaction->member_id_image_url,
            'documents_submitted' => (bool) $transaction->documents_submitted,
            'attachments' => $transaction->attachments
                ->whereIn('status', ['active', 'pending_upload', 'upload_failed', 'delete_failed'])
                ->map(fn ($attachment) => [
                    'transaction_attachment_id' => $attachment->transaction_attachment_id,
                    'category' => $attachment->category,
                    'label' => $attachment->label,
                    'original_name' => $attachment->original_name,
                    'status' => $attachment->status,
                ])
                ->values(),
            'regulated_details' => $transaction->regulated_details,
        ];
    }
}
