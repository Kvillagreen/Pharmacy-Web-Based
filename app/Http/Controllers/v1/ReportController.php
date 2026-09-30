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
            ->whereIn('branch_id', $scopeBranchIds);

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
            ->join('medicines', 'transaction_items.medicine_id', '=', 'medicines.medicine_id')
            ->selectRaw('medicines.category, SUM(transaction_items.quantity) as quantity_sold, COUNT(DISTINCT transactions.transaction_id) as transaction_count')
            ->whereIn('transactions.branch_id', $scopeBranchIds)
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
            ->with(['user:user_id,first_name,last_name', 'branch:branch_id,branch_name', 'attachments'])
            ->whereIn('branch_id', $scopeBranchIds)
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->latest('created_at')
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
                'created_at' => $transaction->created_at,
            ])
            ->values();

        $prescribedTransactions = Transaction::query()
            ->with(['user:user_id,first_name,last_name', 'branch:branch_id,branch_name', 'attachments'])
            ->whereIn('branch_id', $scopeBranchIds)
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
        $rules = [
            'company_id' => ['required', 'integer', 'exists:companies,company_id'],
            'branch_id' => ['required', 'integer', 'exists:branches,branch_id'],
            'year' => ['required', 'integer', 'min:2018', 'max:'.now()->year],
            'quarter' => ['required', 'integer', 'between:1,3'],
            'tax_method' => ['required', 'in:graduated_itemized,graduated_osd,eight_percent'],
            'income_type' => ['required', 'in:business,mixed'],
            'previous_income' => ['nullable', 'numeric', 'between:-999999999999,999999999999'],
            'cost_of_sales_override' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
        ];
        foreach (['itemized_deductions', 'other_income', 'gpp_income', 'prior_year_credit', 'previous_payments', 'previous_withholding', 'current_withholding', 'amended_payment', 'foreign_credit', 'other_credits', 'surcharge', 'interest', 'compromise'] as $field) {
            $rules[$field] = ['nullable', 'numeric', 'min:0', 'max:999999999999'];
        }
        $data = $request->validate($rules);
        $branch = Branch::with('company')->where('company_id', $data['company_id'])
            ->where('status', 'active')->find($data['branch_id']);
        if (!$branch) {
            return response()->json(['success' => false, 'message' => 'Selected branch is not available for the specified company.'], 422);
        }
        $start = Carbon::create($data['year'], ($data['quarter'] - 1) * 3 + 1, 1)->startOfDay();
        $end = $start->copy()->endOfQuarter();
        if ($end->isFuture()) {
            return response()->json(['success' => false, 'message' => 'Select a completed quarter.'], 422);
        }
        if ((int) $data['quarter'] === 1 && (float) ($data['previous_income'] ?? 0) !== 0.0) {
            return response()->json(['success' => false, 'message' => 'Previous-quarter income must be zero for the first quarter.'], 422);
        }
        $salesQuery = Transaction::query()->where('branch_id', $branch->branch_id)
            ->whereBetween('created_at', [$start, $end])->whereNull('voided_at')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'completed'));
        $summary = (clone $salesQuery)->selectRaw('COALESCE(SUM(sub_total),0) gross_sales, COALESCE(SUM(discount),0) discounts, COALESCE(SUM(vat_amount),0) vat, COALESCE(SUM(total_amount),0) receipts, COUNT(*) transaction_count')->first();
        $items = DB::table('transaction_items')->whereIn('transaction_id', (clone $salesQuery)->select('transaction_id'));
        $cost = round((float) (clone $items)->selectRaw('COALESCE(SUM(quantity * cost_price),0) total')->value('total'), 2);
        $missing = (clone $items)->whereNull('cost_price')->count();
        $missingItems = (clone $salesQuery)->doesntHave('items')->count();
        $netSales = round((float) $summary->receipts - (float) $summary->vat, 2);
        $notes = [
            'Branch worksheet based on the supplied BIR Form 1701Q (January 2018). This individual-income-tax form is not a corporate return. Consolidate all taxpayer income before filing.',
            'Only completed, non-voided sales in the selected quarter are included. Net sales exclude recorded VAT. Historical unit costs are used without substituting current medicine prices.',
            'Prior-quarter income, expenses, tax credits and assessed penalties are entered by the preparer; zero means none entered. VAT is not a withholding tax credit.',
            'Form computation rows round to whole pesos as instructed by the template. Source transaction totals retain centavos.',
            'Spouse information, aggregate spouse liability, payment evidence and taxpayer declarations require completion on the official return. This worksheet does not certify tax payment.',
        ];
        if ($missing || $missingItems) {
            $notes[] = "$missing item(s) lack historical costs; $missingItems transaction(s) lack item details. Reconcile cost of sales before using itemized deductions.";
        }
        if (isset($data['cost_of_sales_override'])) {
            $cost = (float) $data['cost_of_sales_override'];
            $notes[] = 'Cost of sales uses the preparer-entered override rather than the incomplete recorded cost total.';
        }
        if ($data['tax_method'] === 'eight_percent') {
            $cumulative = $netSales + (float) ($data['other_income'] ?? 0) + (float) ($data['previous_income'] ?? 0);
            if ($cumulative > 3000000 || (float) $summary->vat > 0) {
                return response()->json(['success' => false, 'message' => 'The 8% option requires eligible non-VAT income within the PHP 3,000,000 limit. Select graduated rates and reconcile prior-quarter income.'], 422);
            }
            $notes[] = 'The preparer must confirm eligibility and election of the 8% option across all taxpayer businesses.';
        }
        $calculation = app(\App\Services\v1\QuarterlyTaxSummary::class)->calculate($netSales, $cost, $data);
        return response()->json(['success' => true, 'data' => array_merge([
            'form_no' => '1701Q', 'generated_at' => now()->toIso8601String(),
            'branch_id' => $branch->branch_id, 'branch_name' => $branch->branch_name,
            'company_id' => $branch->company_id, 'taxpayer_name' => $branch->company?->company_name,
            'tin_number' => $branch->company?->tin_number, 'taxable_year' => (int) $data['year'],
            'quarter' => (int) $data['quarter'], 'period_start' => $start->toDateString(), 'return_period' => $end->toDateString(),
            'tax_method' => $data['tax_method'], 'income_type' => $data['income_type'],
            'atc' => $data['tax_method'] === 'eight_percent' ? ($data['income_type'] === 'mixed' ? 'II016' : 'II015') : ($data['income_type'] === 'mixed' ? 'II013' : 'II012'),
            'registered_address' => $branch->branch_address, 'telephone_number' => $branch->branch_contact,
            'transaction_count' => (int) $summary->transaction_count,
            'gross_sales_receipts' => round((float) $summary->gross_sales, 2),
            'sales_discounts' => round((float) $summary->discounts, 2),
            'vat_amount' => round((float) $summary->vat, 2),
            'net_receipts' => round((float) $summary->receipts, 2),
            'net_sales_receipts' => $netSales, 'cost_of_sales' => $cost,
            'missing_cost_count' => $missing, 'missing_item_transaction_count' => $missingItems,
            'period_from' => $start->toDateString(), 'period_to' => $end->toDateString(),
            'surcharge' => (float) ($data['surcharge'] ?? 0), 'interest' => (float) ($data['interest'] ?? 0), 'compromise' => (float) ($data['compromise'] ?? 0),
            'inputs' => $data, 'is_ready_to_file' => false, 'data_notes' => $notes,
        ], $calculation)]);
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

    public function storeBir2306(Request $request)
    {
        $data = $request->validate([
            'company_id' => ['required','integer','exists:companies,company_id'],
            'branch_id' => ['required','integer','exists:branches,branch_id'],
            'period_from' => ['required','date'], 'period_to' => ['required','date','after_or_equal:period_from'],
            'payor_tin' => ['required','string','max:30'], 'payor_registered_name' => ['required','string'],
            'payor_registered_address' => ['required','string'], 'payor_zip_code' => ['required','string','max:10'],
            'nature_of_income_payment' => ['required','string'], 'atc' => ['required','string','max:20'],
            'source_reference' => ['required','string','max:255'],
            'payor_signatory_name' => ['nullable','string'], 'payor_signatory_title' => ['nullable','string'],
            'certificate_date' => ['nullable','date'],
        ]);
        $branchValid = Branch::whereKey($data['branch_id'])->where('company_id',$data['company_id'])->exists();
        if (!$branchValid) return response()->json(['success'=>false,'message'=>'Branch does not belong to the selected company.'],422);
        $summary = Transaction::where('branch_id',$data['branch_id'])->where('status','completed')->whereBetween('created_at',[Carbon::parse($data['period_from'])->startOfDay(),Carbon::parse($data['period_to'])->endOfDay()])->selectRaw('COALESCE(SUM(total_amount),0) amount_of_payment, COALESCE(SUM(vat_amount),0) tax_withheld')->first();
        $record = Bir2306Record::create([...$data,'amount_of_payment'=>(float)$summary->amount_of_payment,'tax_withheld'=>(float)$summary->tax_withheld]);
        return response()->json(['success'=>true,'message'=>'BIR 2306 source record stored successfully.','data'=>$record],201);
    }

    public function export(Request $request)
    {
        $format = strtolower((string) $request->validate(['format' => ['required','in:csv,pdf']])['format']);
        $payload = $this->index($request)->getData(true)['data'];
        $rows = $payload['tables']['recent_transactions'] ?? [];
        if ($format === 'csv') {
            $stream = fopen('php://temp', 'r+');
            fputcsv($stream, ['Transaction ID','Branch','Cashier','Payment','Type','Amount','Discount','Date']);
            foreach ($rows as $row) fputcsv($stream, [$row['transaction_id'],$row['branch_name'],$row['cashier_name'],$row['payment_method'],$row['transaction_type'],$row['total_amount'],$row['discount'],$row['created_at']]);
            rewind($stream); $content = stream_get_contents($stream); fclose($stream);
            return response($content, 200, ['Content-Type'=>'text/csv; charset=UTF-8','Content-Disposition'=>'attachment; filename="pharmacy-report.csv"']);
        }
        $lines = ['PHARMACY SALES REPORT','Scope: '.($payload['scope']['label'] ?? ''),'Revenue: '.number_format((float)($payload['summary']['total_revenue'] ?? 0),2),'Transactions: '.($payload['summary']['transaction_count'] ?? 0)];
        foreach (array_slice($rows,0,35) as $row) $lines[] = '#'.$row['transaction_id'].' '.$row['branch_name'].' '.number_format((float)$row['total_amount'],2);
        return response($this->simplePdf($lines),200,['Content-Type'=>'application/pdf','Content-Disposition'=>'attachment; filename="pharmacy-report.pdf"']);
    }

    private function simplePdf(array $lines): string
    {
        $escape = fn ($v) => str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], (string)$v);
        $text = "BT /F1 11 Tf 50 790 Td ";
        foreach ($lines as $i => $line) $text .= ($i ? "0 -18 Td " : '') . '(' . $escape($line) . ") Tj ";
        $text .= 'ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.strlen($text).' >> stream' . "\n" . $text . "\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n"; $offsets=[0];
        foreach ($objects as $i=>$object) { $offsets[] = strlen($pdf); $pdf .= ($i+1)." 0 obj\n{$object}\nendobj\n"; }
        $xref=strlen($pdf); $pdf.="xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";
        for($i=1;$i<=count($objects);$i++) $pdf.=sprintf("%010d 00000 n \n",$offsets[$i]);
        return $pdf."trailer << /Size ".(count($objects)+1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }
}
