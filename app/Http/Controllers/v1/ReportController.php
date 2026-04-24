<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\v1\Batch;
use App\Models\v1\Branch;
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
        $days = max(7, min((int) $request->input('days', 30), 90));

        $today = Carbon::today();
        $rangeStart = $today->copy()->subDays($days - 1)->startOfDay();
        $rangeEnd = $today->copy()->endOfDay();
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
                        'payment_mix' => [],
                        'category_mix' => [],
                    ],
                    'tables' => [
                        'branch_performance' => [],
                        'top_medicines' => [],
                        'inventory_watch' => [],
                        'recent_transactions' => [],
                    ],
                    'analysis' => [
                        'headline' => 'No report data is available for the selected scope yet.',
                        'highlights' => [],
                    ],
                ],
            ]);
        }

        $transactionSummary = Transaction::query()
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
            ->selectRaw(
                'COALESCE(SUM(medicines.price * inventories.stocks), 0) as inventory_value,
                 SUM(CASE WHEN inventories.stocks <= medicines.reorder_level THEN 1 ELSE 0 END) as low_stock_count'
            )
            ->whereIn('inventories.branch_id', $scopeBranchIds)
            ->first();

        $inventoryValue = (float) ($inventorySummary->inventory_value ?? 0);
        $lowStockCount = (int) ($inventorySummary->low_stock_count ?? 0);

        $batchSummary = Batch::query()
            ->join('inventories', 'inventories.batch_id', '=', 'batches.batch_id')
            ->whereIn('inventories.branch_id', $scopeBranchIds)
            ->selectRaw(
                'COUNT(DISTINCT CASE WHEN expiry_date >= ? AND expiry_date <= ? THEN batches.batch_id END) as expiring_30_count',
                [$today->toDateString(), $today->copy()->addDays(30)->toDateString()]
            )
            ->first();

        $expiring30Count = (int) ($batchSummary->expiring_30_count ?? 0);

        $dailyRevenueRaw = Transaction::query()
            ->selectRaw('DATE(created_at) as sale_date, SUM(total_amount) as total_revenue, COUNT(*) as transaction_count')
            ->whereIn('branch_id', $scopeBranchIds)
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

        $paymentMix = Transaction::query()
            ->selectRaw('payment_method, SUM(total_amount) as total_revenue, COUNT(*) as transaction_count')
            ->whereIn('branch_id', $scopeBranchIds)
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
            ->when($companyId > 0, fn ($query) => $query->where('branches.company_id', $companyId))
            ->selectRaw('branches.branch_id, branches.branch_name, COALESCE(SUM(transactions.total_amount), 0) as total_revenue, COUNT(transactions.transaction_id) as transaction_count')
            ->groupBy('branches.branch_id', 'branches.branch_name')
            ->orderByDesc('total_revenue')
            ->get()
            ->map(fn ($row) => [
                'branch_id' => $row->branch_id,
                'branch_name' => $row->branch_name,
                'total_revenue' => (float) $row->total_revenue,
                'transaction_count' => (int) $row->transaction_count,
            ])
            ->values();

        $topMedicines = DB::table('transaction_items')
            ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.transaction_id')
            ->join('medicines', 'transaction_items.medicine_id', '=', 'medicines.medicine_id')
            ->selectRaw('medicines.medicine_id, medicines.medicine_name, medicines.generic_name, medicines.category, SUM(transaction_items.quantity) as quantity_sold, COUNT(DISTINCT transactions.transaction_id) as transactions_count')
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
                batches.expiry_date
            ')
            ->whereIn('inventories.branch_id', $scopeBranchIds)
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
                    'status' => $status,
                ];
            })
            ->values();

        $recentTransactions = Transaction::query()
            ->with(['user:user_id,first_name,last_name', 'branch:branch_id,branch_name'])
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
                'total_amount' => (float) $transaction->total_amount,
                'discount' => (float) ($transaction->discount ?? 0),
                'created_at' => $transaction->created_at,
            ])
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
                    'payment_mix' => $paymentMix,
                    'category_mix' => $categoryMix,
                ],
                'tables' => [
                    'branch_performance' => $branchPerformance,
                    'top_medicines' => $topMedicines,
                    'inventory_watch' => $inventoryWatch,
                    'recent_transactions' => $recentTransactions,
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
                'message' => 'BIR annual declarations can only be generated for a completed taxable year.',
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
            ->where('branch_id', $branch->branch_id)
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
        $costOfSales = 0.00;
        $grossIncome = round($netSales - $costOfSales, 2);
        $deductions = 0.00;
        $taxableNetIncome = round(max($grossIncome - $deductions, 0), 2);
        $incomeTaxRate = 0.25;
        $incomeTaxDue = round($taxableNetIncome * $incomeTaxRate, 2);

        return response()->json([
            'success' => true,
            'data' => [
                'form_no' => '1702-RT',
                'generated_at' => now(),
                'branch_id' => $branch->branch_id,
                'branch_name' => $branch->branch_name,
                'company_id' => $branch->company?->company_id,
                'taxpayer_name' => trim(($branch->company?->company_name ?? 'Pharmacy') . ' - ' . $branch->branch_name . ' Branch'),
                'tin_number' => $branch->company?->tin_number,
                'taxable_year' => $selectedYear,
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
                'is_ready_to_file' => false,
                'data_notes' => [
                    'This is a BIR Form 1702-RT style annual declaration summary generated from recorded sales transactions.',
                    'Cost of sales and itemized deductions are currently set to 0.00 because the system does not yet store audited cost and expense ledgers.',
                    'Please reconcile this summary with your accountant, audited financial statements, and official BIR filing requirements before submission.',
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
}
