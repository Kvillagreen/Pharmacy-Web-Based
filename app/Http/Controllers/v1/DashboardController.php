<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\v1\Batch;
use App\Models\v1\Branch;
use App\Models\v1\Medicine;
use App\Models\v1\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    private function buildCacheKey(int $companyId, int $branchId, int $days): string
    {
        return sprintf('dashboard_summary_%d_%d_%d', $companyId, $branchId, $days);
    }

    public function index(Request $request)
    {
        $companyId = (int) $request->input('company_id', 0);
        $branchId = (int) $request->input('branch_id', 0);
        $authUser = $request->user();
        if ($authUser && !in_array($authUser->role, ['admin', 'owner', 'super_admin'], true)) {
            $branchId = (int) $authUser->branch_id;
        }
        $days = max(7, min((int) $request->input('days', 30), 90));
        $cacheKey = $this->buildCacheKey($companyId, $branchId, $days);

        $payload = Cache::remember($cacheKey, 0, function () use ($companyId, $branchId, $days) {
            $today = Carbon::today();
            $rangeStart = $today->copy()->subDays($days - 1)->startOfDay();
            $rangeEnd = $today->copy()->endOfDay();
            $previousRangeStart = $rangeStart->copy()->subDays($days);
            $previousRangeEnd = $rangeStart->copy()->subDay()->endOfDay();
            $monthStart = $today->copy()->startOfMonth();
            $lastMonthStart = $today->copy()->subMonthNoOverflow()->startOfMonth();
            $lastMonthEnd = $today->copy()->subMonthNoOverflow()->endOfMonth();

            $baseBranchQuery = Branch::query()->where('status', 'active');
            if ($companyId > 0) {
                $baseBranchQuery->where('company_id', $companyId);
            }

            $companyBranchIds = $baseBranchQuery->pluck('branch_id')->toArray();
            $scopeBranchIds = $companyBranchIds;

            $scopeLabel = 'All Branches';
            if ($branchId > 0) {
                $selectedBranch = Branch::query()
                    ->where('status', 'active')
                    ->where('branch_id', $branchId)
                    ->when($companyId > 0, fn ($query) => $query->where('company_id', $companyId))
                    ->select(['branch_id', 'branch_name'])
                    ->first();

                if ($selectedBranch) {
                    $scopeBranchIds = [$selectedBranch->branch_id];
                    $scopeLabel = $selectedBranch->branch_name ?: 'Selected Branch';
                } else {
                    $scopeBranchIds = [];
                    $scopeLabel = 'Selected Branch';
                }
            }

            if (empty($scopeBranchIds)) {
                return [
                    'scope' => [
                        'company_id' => $companyId,
                        'branch_id' => $branchId,
                        'days' => $days,
                        'label' => $scopeLabel,
                    ],
                    'summary' => [
                        'total_revenue' => 0,
                        'revenue_change_pct' => 0,
                        'transaction_count' => 0,
                        'transaction_change_pct' => 0,
                        'average_sale' => 0,
                        'inventory_value' => 0,
                        'low_stock_count' => 0,
                        'out_of_stock_count' => 0,
                        'expiring_30_count' => 0,
                        'expired_count' => 0,
                    ],
                    'charts' => [
                        'daily_revenue' => [],
                        'payment_mix' => [],
                        'category_mix' => [],
                        'branch_comparison' => [],
                    ],
                    'tables' => [
                        'top_medicines' => [],
                        'recent_transactions' => [],
                        'branch_table' => [],
                    ],
                    'analysis' => [
                        'headline' => 'No dashboard data is available for the selected scope yet.',
                        'highlights' => [],
                    ],
                ];
            }

            $aggregateStart = $previousRangeStart->lessThan($lastMonthStart) ? $previousRangeStart : $lastMonthStart;
            $aggregateEnd = $rangeEnd->greaterThan($lastMonthEnd) ? $rangeEnd : $lastMonthEnd;

            $transactionSummary = Transaction::query()
                ->whereIn('branch_id', $scopeBranchIds)
                ->whereBetween('created_at', [$aggregateStart, $aggregateEnd])
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN created_at BETWEEN ? AND ? THEN total_amount ELSE 0 END), 0) as current_revenue,
                     COALESCE(SUM(CASE WHEN created_at BETWEEN ? AND ? THEN total_amount ELSE 0 END), 0) as previous_revenue,
                     SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as current_transactions,
                     SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as previous_transactions,
                     COALESCE(SUM(CASE WHEN created_at BETWEEN ? AND ? THEN total_amount ELSE 0 END), 0) as current_month_revenue,
                     COALESCE(SUM(CASE WHEN created_at BETWEEN ? AND ? THEN total_amount ELSE 0 END), 0) as last_month_revenue',
                    [
                        $rangeStart, $rangeEnd,
                        $previousRangeStart, $previousRangeEnd,
                        $rangeStart, $rangeEnd,
                        $previousRangeStart, $previousRangeEnd,
                        $monthStart, $rangeEnd,
                        $lastMonthStart, $lastMonthEnd,
                    ]
                )
                ->first();

            $currentRevenue = (float) ($transactionSummary->current_revenue ?? 0);
            $previousRevenue = (float) ($transactionSummary->previous_revenue ?? 0);
            $currentTransactions = (int) ($transactionSummary->current_transactions ?? 0);
            $previousTransactions = (int) ($transactionSummary->previous_transactions ?? 0);

            $averageSale = $currentTransactions > 0 ? round($currentRevenue / $currentTransactions, 2) : 0;

            $inventorySummary = DB::table('inventories')
                ->join('medicines', 'medicines.medicine_id', '=', 'inventories.medicine_id')
                ->leftJoin('batches', 'inventories.batch_id', '=', 'batches.batch_id')
                ->selectRaw(
                    'COUNT(*) as total_items,
                     COALESCE(SUM(medicines.price * inventories.stocks), 0) as inventory_value,
                     SUM(CASE WHEN inventories.stocks <= medicines.reorder_level THEN 1 ELSE 0 END) as low_stock_count,
                     SUM(CASE WHEN inventories.stocks <= 0 THEN 1 ELSE 0 END) as out_of_stock_count'
                )
                ->whereIn('inventories.branch_id', $scopeBranchIds)
                ->where(function ($statusQuery) {
                    $statusQuery->whereNull('batches.status')
                        ->orWhereNotIn('batches.status', ['archived', 'pulled_out', 'disposed', 'deleted']);
                })
                ->first();

            $inventoryValue = (float) ($inventorySummary->inventory_value ?? 0);
            $lowStockCount = (int) ($inventorySummary->low_stock_count ?? 0);
            $outOfStockCount = (int) ($inventorySummary->out_of_stock_count ?? 0);

            $batchSummary = Batch::query()
                ->join('inventories', 'inventories.batch_id', '=', 'batches.batch_id')
                ->whereIn('inventories.branch_id', $scopeBranchIds)
                ->where(function ($statusQuery) {
                    $statusQuery->whereNull('batches.status')
                        ->orWhereNotIn('batches.status', ['archived', 'pulled_out', 'disposed', 'deleted']);
                })
                ->selectRaw(
                    'COUNT(DISTINCT CASE WHEN expiry_date >= ? AND expiry_date <= ? THEN batches.batch_id END) as expiring_30_count,
                     COUNT(DISTINCT CASE WHEN expiry_date < ? THEN batches.batch_id END) as expired_count',
                    [
                        $today->toDateString(),
                        $today->copy()->addDays(30)->toDateString(),
                        $today->toDateString(),
                    ]
                )
                ->first();

            $expiring30Count = (int) ($batchSummary->expiring_30_count ?? 0);
            $expiredCount = (int) ($batchSummary->expired_count ?? 0);

            $dailyRevenueRaw = Transaction::query()
                ->selectRaw('DATE(created_at) as sale_date, SUM(total_amount) as total_revenue, COUNT(*) as transaction_count')
                ->whereIn('branch_id', $scopeBranchIds)
                ->whereBetween('created_at', [$rangeStart, $rangeEnd])
                ->groupBy(DB::raw('DATE(created_at)'))
                ->orderBy('sale_date')
                ->get()
                ->keyBy('sale_date');

            $dailyRevenue = collect(range(0, $days - 1))->map(fn ($offset) => [
                'date' => $rangeStart->copy()->addDays($offset)->toDateString(),
                'label' => $rangeStart->copy()->addDays($offset)->format('M d'),
                'total_revenue' => (float) ($dailyRevenueRaw->get($rangeStart->copy()->addDays($offset)->toDateString())?->total_revenue ?? 0),
                'transaction_count' => (int) ($dailyRevenueRaw->get($rangeStart->copy()->addDays($offset)->toDateString())?->transaction_count ?? 0),
            ])->values();

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
                ->limit(6)
                ->get()
                ->map(fn ($row) => [
                    'category' => $row->category,
                    'quantity_sold' => (int) $row->quantity_sold,
                    'transaction_count' => (int) $row->transaction_count,
                ])
                ->values();

            $topMedicines = DB::table('transaction_items')
                ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.transaction_id')
                ->join('medicines', 'transaction_items.medicine_id', '=', 'medicines.medicine_id')
                ->selectRaw('medicines.medicine_id, medicines.medicine_name, medicines.generic_name, medicines.category, medicines.price, SUM(transaction_items.quantity) as quantity_sold, COUNT(DISTINCT transactions.transaction_id) as transactions_count')
                ->whereIn('transactions.branch_id', $scopeBranchIds)
                ->whereBetween('transactions.created_at', [$rangeStart, $rangeEnd])
                ->groupBy('medicines.medicine_id', 'medicines.medicine_name', 'medicines.generic_name', 'medicines.category', 'medicines.price')
                ->orderByDesc('quantity_sold')
                ->limit(6)
                ->get()
                ->map(fn ($row) => [
                    'medicine_id' => $row->medicine_id,
                    'medicine_name' => $row->medicine_name,
                    'generic_name' => $row->generic_name,
                    'category' => $row->category,
                    'price' => (float) $row->price,
                    'quantity_sold' => (int) $row->quantity_sold,
                    'transactions_count' => (int) $row->transactions_count,
                ])
                ->values();

            $recentTransactions = Transaction::query()
                ->with(['user:user_id,first_name,last_name', 'branch:branch_id,branch_name'])
                ->whereIn('branch_id', $scopeBranchIds)
                ->latest('created_at')
                ->limit(8)
                ->get()
                ->map(fn ($transaction) => [
                    'transaction_id' => $transaction->transaction_id,
                    'branch_name' => $transaction->branch?->branch_name,
                    'cashier_name' => trim(($transaction->user?->first_name ?? '') . ' ' . ($transaction->user?->last_name ?? '')),
                    'payment_method' => $transaction->payment_method,
                    'total_amount' => (float) $transaction->total_amount,
                    'discount' => (float) ($transaction->discount ?? 0),
                    'status' => $transaction->status,
                    'voided_at' => $transaction->voided_at,
                    'created_at' => $transaction->created_at,
                ])
                ->values();

            $branchComparison = Branch::query()
                ->leftJoin('transactions', function ($join) use ($monthStart, $rangeEnd) {
                    $join->on('branches.branch_id', '=', 'transactions.branch_id')
                        ->whereBetween('transactions.created_at', [$monthStart, $rangeEnd]);
                })
                ->whereIn('branches.branch_id', $scopeBranchIds)
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

            $currentMonthRevenue = (float) ($transactionSummary->current_month_revenue ?? 0);
            $lastMonthRevenue = (float) ($transactionSummary->last_month_revenue ?? 0);

            return [
                'scope' => [
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'days' => $days,
                    'label' => $scopeLabel,
                ],
                'summary' => [
                    'total_revenue' => round($currentRevenue, 2),
                    'revenue_change_pct' => $this->percentChange($currentRevenue, $previousRevenue),
                    'transaction_count' => $currentTransactions,
                    'transaction_change_pct' => $this->percentChange($currentTransactions, $previousTransactions),
                    'average_sale' => $averageSale,
                    'inventory_value' => round($inventoryValue, 2),
                    'low_stock_count' => $lowStockCount,
                    'out_of_stock_count' => $outOfStockCount,
                    'expiring_30_count' => $expiring30Count,
                    'expired_count' => $expiredCount,
                ],
                'charts' => [
                    'daily_revenue' => $dailyRevenue,
                    'payment_mix' => $paymentMix,
                    'category_mix' => $categoryMix,
                    'branch_comparison' => $branchComparison,
                ],
                'tables' => [
                    'top_medicines' => $topMedicines,
                    'recent_transactions' => $recentTransactions,
                    'branch_table' => $branchComparison,
                ],
                'analysis' => $this->buildAnalysis(
                    $currentRevenue,
                    $previousRevenue,
                    $lowStockCount,
                    $expiring30Count,
                    $topMedicines,
                    $paymentMix,
                    $currentMonthRevenue,
                    $lastMonthRevenue
                ),
            ];
        });

        return response()->json(['success' => true, 'data' => $payload]);
    }

    private function percentChange(float|int $current, float|int $previous): float
    {
        if ((float) $previous === 0.0) {
            return (float) $current > 0 ? 100.0 : 0.0;
        }

        return round((((float) $current - (float) $previous) / (float) $previous) * 100, 2);
    }

    private function buildAnalysis(
        float $currentRevenue,
        float $previousRevenue,
        int $lowStockCount,
        int $expiring30Count,
        $topMedicines,
        $paymentMix,
        float $currentMonthRevenue,
        float $lastMonthRevenue
    ): array {
        $bestMedicine = $topMedicines->first();
        $leadingPaymentMethod = $paymentMix->first();
        $revenueTrend = $this->percentChange($currentRevenue, $previousRevenue);
        $monthTrend = $this->percentChange($currentMonthRevenue, $lastMonthRevenue);

        $headline = 'Sales are stable for the selected period.';
        if ($revenueTrend > 8) {
            $headline = 'Revenue is trending upward over the selected period.';
        } elseif ($revenueTrend < -8) {
            $headline = 'Revenue is softer in the selected period and needs attention.';
        }

        $highlights = [];

        $highlights[] = $monthTrend >= 0
            ? 'Month-to-date revenue is ahead of last month by ' . $monthTrend . ' percent.'
            : 'Month-to-date revenue is behind last month by ' . abs($monthTrend) . ' percent.';

        if (is_array($bestMedicine) && !empty($bestMedicine['medicine_name'])) {
            $highlights[] = $bestMedicine['medicine_name'] . ' is the current top-moving product with ' . $bestMedicine['quantity_sold'] . ' units sold.';
        }

        if (is_array($leadingPaymentMethod) && !empty($leadingPaymentMethod['payment_method'])) {
            $highlights[] = $leadingPaymentMethod['payment_method'] . ' is the leading payment channel in the selected range.';
        }

        if ($lowStockCount > 0) {
            $highlights[] = $lowStockCount . ' medicine records are already at or below reorder level.';
        }

        if ($expiring30Count > 0) {
            $highlights[] = $expiring30Count . ' batches will expire within 30 days and should be prioritized in FEFO handling.';
        }

        return [
            'headline' => $headline,
            'highlights' => $highlights,
        ];
    }
}
