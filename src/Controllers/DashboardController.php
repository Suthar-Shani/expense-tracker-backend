<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Database;
use App\Helpers\ResponseHelper;

class DashboardController
{
    private function getUserId(Request $request): string
    {
        $user = $request->getAttribute('user');
        return (string)($user['id'] ?? '');
    }

    /**
     * Master Endpoint: Full Dashboard payload containing all sections.
     * GET /api/dashboard
     */
    public function index(Request $request, Response $response): Response
    {
        $userId = $this->getUserId($request);
        $params = $request->getQueryParams();
        $targetMonth = !empty($params['month']) ? trim($params['month']) : date('Y-m');
        $overviewMonths = isset($params['months']) ? max(1, min(24, (int)$params['months'])) : 6;
        $recentLimit = isset($params['limit']) ? max(1, min(50, (int)$params['limit'])) : 5;

        try {
            $tfInfo = $this->resolveTimeframe($params);
            $summary = $this->buildSummaryData($userId, $targetMonth, $params);
            $financialPerformance = $this->buildFinancialPerformanceData($userId, $params);
            $spendingOverview = $this->buildSpendingOverviewData($userId, $overviewMonths);
            $expenseBreakdown = $this->buildExpenseBreakdownData($userId, $targetMonth, [
                'start' => $tfInfo['start'],
                'end' => $tfInfo['end']
            ]);
            $monthlyAverages = $this->buildMonthlyAveragesData($userId, $params);
            $recentTransactions = $this->buildRecentTransactionsData($userId, $recentLimit);

            $dashboardData = [
                'summary' => $summary,
                'financial_performance' => $financialPerformance,
                'spending_overview' => $spendingOverview,
                'expense_breakdown' => $expenseBreakdown,
                'monthly_averages' => $monthlyAverages,
                'recent_transactions' => $recentTransactions,
            ];

            return ResponseHelper::success($response, $dashboardData, 'Dashboard data retrieved successfully');
        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to fetch dashboard data: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Dedicated Financial Performance Screen Endpoint (Direct UI match).
     * Returns:
     * - Top 4 KPI Cards: Total Income, Total Expenses, Net Savings, Savings Rate
     * - Spending Overview (Monthly Income vs Expenses Chart)
     * - Expense Breakdown (Donut chart & Top spending categories)
     * - Monthly Averages (Avg Income, Avg Expenses, Avg Savings with % vs prior period)
     * 
     * GET /api/dashboard/financial-performance
     * GET /api/financial-performance
     * Query params:
     * - timeframe: this_month | last_month | last_3_months | this_year (default: this_month)
     * - month: YYYY-MM (optional specific month)
     * - start_date & end_date: YYYY-MM-DD (optional custom range)
     */
    public function financialPerformance(Request $request, Response $response): Response
    {
        $userId = $this->getUserId($request);
        $params = $request->getQueryParams();

        try {
            $data = $this->buildFinancialPerformanceData($userId, $params);
            return ResponseHelper::success($response, $data, 'Financial performance data retrieved successfully');
        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to fetch financial performance: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Dedicated Monthly Averages Endpoint.
     * Historical monthly trends and indicators (Avg Monthly Income, Expenses, Savings).
     * 
     * GET /api/dashboard/monthly-averages
     * Query params:
     * - months: count of months to compute averages over (default: 6)
     */
    public function monthlyAverages(Request $request, Response $response): Response
    {
        $userId = $this->getUserId($request);
        $params = $request->getQueryParams();

        try {
            $data = $this->buildMonthlyAveragesData($userId, $params);
            return ResponseHelper::success($response, $data, 'Monthly averages retrieved successfully');
        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to fetch monthly averages: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Section 1: KPI Summary Cards (Total Balance, Monthly Income, Monthly Expense, Net Savings, Savings Rate).
     * GET /api/dashboard/summary
     */
    public function summary(Request $request, Response $response): Response
    {
        $userId = $this->getUserId($request);
        $params = $request->getQueryParams();
        $targetMonth = !empty($params['month']) ? trim($params['month']) : date('Y-m');

        try {
            $data = $this->buildSummaryData($userId, $targetMonth, $params);
            return ResponseHelper::success($response, $data, 'Dashboard summary cards retrieved');
        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to fetch summary: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Section 2: Spending Overview (Monthly Income vs Expenses Chart).
     * GET /api/dashboard/spending-overview
     */
    public function spendingOverview(Request $request, Response $response): Response
    {
        $userId = $this->getUserId($request);
        $params = $request->getQueryParams();
        $monthsCount = isset($params['months']) ? max(1, min(24, (int)$params['months'])) : 6;

        try {
            $data = $this->buildSpendingOverviewData($userId, $monthsCount);
            return ResponseHelper::success($response, $data, 'Spending overview chart data retrieved');
        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to fetch spending overview: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Section 3: Expense Breakdown (Top spending categories & donut chart).
     * GET /api/dashboard/expense-breakdown
     */
    public function expenseBreakdown(Request $request, Response $response): Response
    {
        $userId = $this->getUserId($request);
        $params = $request->getQueryParams();
        $targetMonth = isset($params['month']) ? trim($params['month']) : date('Y-m');
        if (strtolower($targetMonth) === 'all') {
            $targetMonth = null;
        }

        $tfInfo = $this->resolveTimeframe($params);
        $dateRange = !empty($params['timeframe']) ? ['start' => $tfInfo['start'], 'end' => $tfInfo['end']] : null;

        try {
            $data = $this->buildExpenseBreakdownData($userId, $targetMonth, $dateRange);
            return ResponseHelper::success($response, $data, 'Expense breakdown data retrieved');
        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to fetch expense breakdown: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Section 4: Recent Transactions list.
     * GET /api/dashboard/recent-transactions
     */
    public function recentTransactions(Request $request, Response $response): Response
    {
        $userId = $this->getUserId($request);
        $params = $request->getQueryParams();
        $limit = isset($params['limit']) ? max(1, min(50, (int)$params['limit'])) : 5;

        try {
            $data = $this->buildRecentTransactionsData($userId, $limit);
            return ResponseHelper::success($response, $data, 'Recent transactions retrieved');
        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to fetch recent transactions: ' . $e->getMessage(), 500);
        }
    }

    // =========================================================================
    // FINANCIAL PERFORMANCE SCREEN BUILDER
    // =========================================================================

    /**
     * Build the complete dataset for the Financial Performance screen.
     */
    public function buildFinancialPerformanceData(string $userId, array $params): array
    {
        $tfInfo = $this->resolveTimeframe($params);
        $txCol = Database::getCollection('transactions');

        // Fetch transactions for fast aggregation
        $txs = $txCol->find(['user_fk' => $userId], [
            'projection' => [
                'type' => 1,
                'amount' => 1,
                'date' => 1,
                'category_fk' => 1
            ]
        ]);

        $currIncome = 0.0;
        $currExpense = 0.0;
        $priorIncome = 0.0;
        $priorExpense = 0.0;
        $categorySpend = [];

        $startStr = $tfInfo['start'];
        $endStr = $tfInfo['end'];
        $priorStartStr = $tfInfo['prior_start'];
        $priorEndStr = $tfInfo['prior_end'];

        foreach ($txs as $tx) {
            $txDate = trim((string)($tx['date'] ?? ''));
            if ($txDate === '') {
                continue;
            }
            // Standardize format for string comparison
            $txDateTime = strlen($txDate) === 10 ? $txDate . ' 00:00:00' : $txDate;
            $type = strtolower(trim((string)($tx['type'] ?? '')));
            $amt = (float)($tx['amount'] ?? 0);
            $catId = (string)($tx['category_fk'] ?? 'uncategorized');

            // Current Timeframe Check
            if ($txDateTime >= $startStr && $txDateTime <= $endStr) {
                if ($type === 'income') {
                    $currIncome += $amt;
                } elseif ($type === 'expense') {
                    $currExpense += $amt;
                    if (!isset($categorySpend[$catId])) {
                        $categorySpend[$catId] = ['amount' => 0.0, 'tx_count' => 0];
                    }
                    $categorySpend[$catId]['amount'] += $amt;
                    $categorySpend[$catId]['tx_count']++;
                }
            }

            // Prior Timeframe Check
            if ($txDateTime >= $priorStartStr && $txDateTime <= $priorEndStr) {
                if ($type === 'income') {
                    $priorIncome += $amt;
                } elseif ($type === 'expense') {
                    $priorExpense += $amt;
                }
            }
        }

        // Net Savings and Savings Rate
        $currSavings = $currIncome - $currExpense;
        $priorSavings = $priorIncome - $priorExpense;

        $currSavingsRate = $currIncome > 0 ? round(($currSavings / $currIncome) * 100, 1) : 0.0;
        $priorSavingsRate = $priorIncome > 0 ? round(($priorSavings / $priorIncome) * 100, 1) : 0.0;

        // Percentage Trends
        $incomeChangePct = $this->calculatePercentageChange($currIncome, $priorIncome);
        $expenseChangePct = $this->calculatePercentageChange($currExpense, $priorExpense);
        $savingsChangePct = $this->calculatePercentageChange($currSavings, $priorSavings);
        $savingsRateChangePct = round($currSavingsRate - $priorSavingsRate, 1);

        // Preload user categories for Expense Breakdown
        $categoriesCol = Database::getCollection('categories');
        $userCategories = $categoriesCol->find(['user_fk' => $userId]);
        $categoryDetails = [];
        foreach ($userCategories as $cat) {
            $cid = (string)($cat['_id'] ?? $cat['id'] ?? '');
            if ($cid !== '') {
                $categoryDetails[$cid] = [
                    'name' => $cat['name'] ?? 'Other',
                    'iconName' => $cat['iconName'] ?? $cat['icon'] ?? 'Tag',
                    'color' => $cat['color'] ?? 'blue',
                    'budget' => (float)($cat['budget'] ?? $cat['baseAmount'] ?? 0)
                ];
            }
        }

        // Format Expense Breakdown list sorted descending
        $breakdownList = [];
        foreach ($categorySpend as $catId => $info) {
            $meta = $categoryDetails[$catId] ?? [
                'name' => ($catId === 'uncategorized') ? 'Uncategorized' : 'Other',
                'iconName' => 'Tag',
                'color' => 'gray',
                'budget' => 0.0
            ];

            $cAmount = round($info['amount'], 2);
            $pct = $currExpense > 0 ? round(($cAmount / $currExpense) * 100, 1) : 0.0;
            $colorInfo = $this->resolveCategoryColor($meta['color']);

            $breakdownList[] = [
                'category_id' => $catId,
                'name' => $meta['name'],
                'iconName' => $meta['iconName'],
                'color' => $colorInfo['name'],
                'color_hex' => $colorInfo['hex'],
                'budget' => $meta['budget'],
                'amount' => $cAmount,
                'formatted' => '₹' . number_format($cAmount, 2),
                'percentage' => $pct,
                'tx_count' => $info['tx_count']
            ];
        }
        usort($breakdownList, fn($a, $b) => $b['amount'] <=> $a['amount']);

        // Spending Overview Chart Data (6 months leading to end of timeframe)
        $overviewMonths = $tfInfo['months_count'] ?? 6;
        $endMonthKey = substr($tfInfo['end'], 0, 7);
        $spendingOverview = $this->buildSpendingOverviewData($userId, $overviewMonths, $endMonthKey);

        // Monthly Averages Section
        $monthlyAverages = $this->buildMonthlyAveragesData($userId, $params);

        return [
            'header' => [
                'title' => 'Financial Performance',
                'subtitle' => 'Overview for selected timeframe',
                'selected_timeframe' => $tfInfo['key'],
                'timeframe_label' => $tfInfo['label'],
                'date_range' => [
                    'start' => substr($tfInfo['start'], 0, 10),
                    'end' => substr($tfInfo['end'], 0, 10)
                ],
                'prior_period_label' => $tfInfo['prior_label'],
                'available_timeframes' => [
                    ['key' => 'this_month', 'label' => 'This Month'],
                    ['key' => 'last_month', 'label' => 'Last Month'],
                    ['key' => 'last_3_months', 'label' => 'Last 3 Months'],
                    ['key' => 'this_year', 'label' => 'This Year']
                ]
            ],
            // 4 Top Metric Cards (Matches Screenshot)
            'kpis' => [
                'total_income' => [
                    'title' => 'TOTAL INCOME',
                    'amount' => round($currIncome, 2),
                    'formatted' => '₹' . number_format($currIncome, 2),
                    'currency' => '₹',
                    'change_percentage' => $incomeChangePct,
                    'change_label' => ($incomeChangePct >= 0 ? '+' : '') . $incomeChangePct . '%',
                    'trend' => $incomeChangePct >= 0 ? 'up' : 'down',
                    'trend_icon' => $incomeChangePct >= 0 ? '↗' : '↘',
                    'is_positive' => $incomeChangePct >= 0,
                    'period_label' => 'vs prior period'
                ],
                'total_expenses' => [
                    'title' => 'TOTAL EXPENSES',
                    'amount' => round($currExpense, 2),
                    'formatted' => '₹' . number_format($currExpense, 2),
                    'currency' => '₹',
                    'change_percentage' => $expenseChangePct,
                    'change_label' => ($expenseChangePct >= 0 ? '+' : '') . $expenseChangePct . '%',
                    'trend' => $expenseChangePct <= 0 ? 'down' : 'up',
                    'trend_icon' => $expenseChangePct <= 0 ? '↘' : '↗',
                    'is_positive' => $expenseChangePct <= 0, // Lower expenses is positive
                    'period_label' => 'vs prior period'
                ],
                'net_savings' => [
                    'title' => 'NET SAVINGS',
                    'amount' => round($currSavings, 2),
                    'formatted' => '₹' . number_format($currSavings, 2),
                    'currency' => '₹',
                    'percentage' => $currSavingsRate,
                    'formatted_percentage' => $currSavingsRate . '%',
                    'change_percentage' => $savingsChangePct,
                    'is_positive' => $currSavings >= 0
                ],
                'savings_rate' => [
                    'title' => 'SAVINGS RATE',
                    'rate' => $currSavingsRate,
                    'formatted' => $currSavingsRate . '%',
                    'change_percentage' => $savingsRateChangePct,
                    'change_label' => ($savingsRateChangePct >= 0 ? '+' : '') . $savingsRateChangePct . '%',
                    'is_positive' => $currSavingsRate >= 0
                ]
            ],
            // Spending Overview (Monthly Income vs Expenses Bar Chart)
            'spending_overview' => [
                'title' => 'Spending Overview',
                'subtitle' => 'Monthly income vs expenses comparison',
                'legend' => [
                    ['key' => 'income', 'label' => 'Income', 'color' => '#10B981'],
                    ['key' => 'expenses', 'label' => 'Expenses', 'color' => '#F43F5E']
                ],
                'chart_data' => $spendingOverview['chart_data'] ?? [],
                'summary' => $spendingOverview['summary'] ?? []
            ],
            // Expense Breakdown (Donut Chart & Top Categories)
            'expense_breakdown' => [
                'title' => 'Expense Breakdown',
                'subtitle' => 'Top spending categories',
                'total_expenses' => round($currExpense, 2),
                'formatted_total' => '₹' . number_format($currExpense, 2),
                'currency' => '₹',
                'timeframe' => $tfInfo['label'],
                'categories' => $breakdownList
            ],
            // Monthly Averages (Bottom Cards)
            'monthly_averages' => $monthlyAverages
        ];
    }

    /**
     * Build Historical Monthly Averages data and prior period comparison indicators.
     */
    public function buildMonthlyAveragesData(string $userId, array $params = []): array
    {
        $monthsCount = isset($params['months']) ? max(1, min(24, (int)$params['months'])) : 6;
        $txCol = Database::getCollection('transactions');

        // Current window: past N months (e.g. Month -5 to Month 0)
        $currWindowKeys = [];
        for ($i = $monthsCount - 1; $i >= 0; $i--) {
            $currWindowKeys[] = date('Y-m', strtotime("-{$i} month"));
        }

        // Prior window: preceding N months (e.g. Month -11 to Month -6)
        $priorWindowKeys = [];
        for ($i = ($monthsCount * 2) - 1; $i >= $monthsCount; $i--) {
            $priorWindowKeys[] = date('Y-m', strtotime("-{$i} month"));
        }

        $txs = $txCol->find(['user_fk' => $userId], [
            'projection' => [
                'type' => 1,
                'amount' => 1,
                'date' => 1
            ]
        ]);

        $currIncome = 0.0;
        $currExpense = 0.0;
        $priorIncome = 0.0;
        $priorExpense = 0.0;

        $currMap = array_fill_keys($currWindowKeys, true);
        $priorMap = array_fill_keys($priorWindowKeys, true);

        foreach ($txs as $tx) {
            $txDate = (string)($tx['date'] ?? '');
            $monthKey = substr($txDate, 0, 7);
            $type = strtolower(trim((string)($tx['type'] ?? '')));
            $amt = (float)($tx['amount'] ?? 0);

            if (isset($currMap[$monthKey])) {
                if ($type === 'income') {
                    $currIncome += $amt;
                } elseif ($type === 'expense') {
                    $currExpense += $amt;
                }
            } elseif (isset($priorMap[$monthKey])) {
                if ($type === 'income') {
                    $priorIncome += $amt;
                } elseif ($type === 'expense') {
                    $priorExpense += $amt;
                }
            }
        }

        $avgCurrIncome = round($currIncome / $monthsCount, 2);
        $avgCurrExpense = round($currExpense / $monthsCount, 2);
        $avgCurrSavings = round($avgCurrIncome - $avgCurrExpense, 2);

        $avgPriorIncome = round($priorIncome / $monthsCount, 2);
        $avgPriorExpense = round($priorExpense / $monthsCount, 2);
        $avgPriorSavings = round($avgPriorIncome - $avgPriorExpense, 2);

        $incomeChange = $this->calculatePercentageChange($avgCurrIncome, $avgPriorIncome);
        $expenseChange = $this->calculatePercentageChange($avgCurrExpense, $avgPriorExpense);
        $savingsChange = $this->calculatePercentageChange($avgCurrSavings, $avgPriorSavings);

        return [
            'title' => 'Monthly Averages',
            'subtitle' => 'Historical monthly trends and indicators',
            'months_analyzed' => $monthsCount,
            'avg_monthly_income' => [
                'title' => 'Avg Monthly Income',
                'amount' => $avgCurrIncome,
                'formatted' => '₹' . number_format($avgCurrIncome, 2),
                'currency' => '₹',
                'change_percentage' => $incomeChange,
                'change_label' => ($incomeChange >= 0 ? '↑ ' : '↓ ') . abs($incomeChange) . '% vs prior period',
                'trend' => $incomeChange >= 0 ? 'up' : 'down',
                'is_positive' => $incomeChange >= 0
            ],
            'avg_monthly_expenses' => [
                'title' => 'Avg Monthly Expenses',
                'amount' => $avgCurrExpense,
                'formatted' => '₹' . number_format($avgCurrExpense, 2),
                'currency' => '₹',
                'change_percentage' => $expenseChange,
                'change_label' => ($expenseChange >= 0 ? '↑ ' : '↓ ') . abs($expenseChange) . '% vs prior period',
                'trend' => $expenseChange <= 0 ? 'down' : 'up',
                'is_positive' => $expenseChange <= 0 // Lower expenses is positive
            ],
            'avg_monthly_savings' => [
                'title' => 'Avg Monthly Savings',
                'amount' => $avgCurrSavings,
                'formatted' => '₹' . number_format($avgCurrSavings, 2),
                'currency' => '₹',
                'change_percentage' => $savingsChange,
                'change_label' => ($savingsChange >= 0 ? '↑ ' : '↓ ') . abs($savingsChange) . '% vs prior period',
                'trend' => $savingsChange >= 0 ? 'up' : 'down',
                'is_positive' => $avgCurrSavings >= 0
            ]
        ];
    }

    /**
     * Build KPI Summary data: Total Balance, Monthly Income, Monthly Expense, Net Savings, Savings Rate.
     */
    private function buildSummaryData(string $userId, string $targetMonth, array $params = []): array
    {
        $accountsCol = Database::getCollection('accounts');
        $txCol = Database::getCollection('transactions');

        // 1. Total Balance from active user accounts
        $accounts = $accountsCol->find(['user_fk' => $userId]);
        $totalBalance = 0.0;
        $hasAccounts = !empty($accounts);
        foreach ($accounts as $acc) {
            $totalBalance += (float)($acc['amount'] ?? 0);
        }

        // 2. Target Month and Previous Month calculations
        $targetTimestamp = strtotime($targetMonth . '-01');
        if (!$targetTimestamp) {
            $targetMonth = date('Y-m');
            $targetTimestamp = strtotime($targetMonth . '-01');
        }
        $lastMonth = date('Y-m', strtotime('-1 month', $targetTimestamp));
        $targetMonthLabel = 'For ' . date('F Y', $targetTimestamp);

        // Fetch transactions for fast in-memory aggregation
        $txs = $txCol->find(['user_fk' => $userId], [
            'projection' => [
                'type' => 1,
                'amount' => 1,
                'date' => 1
            ]
        ]);

        $currIncome = 0.0;
        $currExpense = 0.0;
        $prevIncome = 0.0;
        $prevExpense = 0.0;
        $allIncome = 0.0;
        $allExpense = 0.0;

        foreach ($txs as $tx) {
            $type = strtolower(trim((string)($tx['type'] ?? '')));
            $amt = (float)($tx['amount'] ?? 0);
            $txDate = (string)($tx['date'] ?? '');
            $monthKey = substr($txDate, 0, 7);

            if ($type === 'income') {
                $allIncome += $amt;
                if ($monthKey === $targetMonth) {
                    $currIncome += $amt;
                } elseif ($monthKey === $lastMonth) {
                    $prevIncome += $amt;
                }
            } elseif ($type === 'expense') {
                $allExpense += $amt;
                if ($monthKey === $targetMonth) {
                    $currExpense += $amt;
                } elseif ($monthKey === $lastMonth) {
                    $prevExpense += $amt;
                }
            }
        }

        // If no user accounts exist yet, calculate total balance from income - expense
        if (!$hasAccounts) {
            $totalBalance = $allIncome - $allExpense;
        }

        $currSavings = $currIncome - $currExpense;
        $currSavingsRate = $currIncome > 0 ? round(($currSavings / $currIncome) * 100, 1) : 0.0;

        // Calculate percentage changes vs last month
        $balanceChangePct = $this->calculatePercentageChange($currIncome - $currExpense, $prevIncome - $prevExpense);
        $incomeChangePct = $this->calculatePercentageChange($currIncome, $prevIncome);
        $expenseChangePct = $this->calculatePercentageChange($currExpense, $prevExpense);

        return [
            'total_balance' => [
                'amount' => round($totalBalance, 2),
                'formatted' => '₹' . number_format($totalBalance, 2),
                'currency' => '₹',
                'change_percentage' => $balanceChangePct,
                'change_label' => ($balanceChangePct >= 0 ? '+' : '') . $balanceChangePct . '% vs last month',
                'is_positive' => $balanceChangePct >= 0
            ],
            'monthly_income' => [
                'amount' => round($currIncome, 2),
                'formatted' => '+₹' . number_format($currIncome, 2),
                'currency' => '₹',
                'month' => $targetMonth,
                'subtitle' => $targetMonthLabel,
                'change_percentage' => $incomeChangePct,
                'change_label' => ($incomeChangePct >= 0 ? '+' : '') . $incomeChangePct . '% vs last month',
                'is_positive' => $incomeChangePct >= 0
            ],
            'monthly_expenses' => [
                'amount' => round($currExpense, 2),
                'formatted' => '-₹' . number_format($currExpense, 2),
                'currency' => '₹',
                'month' => $targetMonth,
                'subtitle' => $targetMonthLabel,
                'change_percentage' => $expenseChangePct,
                'change_label' => ($expenseChangePct >= 0 ? '+' : '') . $expenseChangePct . '% vs last month',
                'is_positive' => $expenseChangePct <= 0 // Lower expenses is positive
            ],
            'net_savings' => [
                'amount' => round($currSavings, 2),
                'formatted' => '₹' . number_format($currSavings, 2),
                'currency' => '₹',
                'percentage' => $currSavingsRate,
                'formatted_percentage' => $currSavingsRate . '%',
                'is_positive' => $currSavings >= 0
            ],
            'savings_rate' => [
                'rate' => $currSavingsRate,
                'formatted' => $currSavingsRate . '%',
                'is_positive' => $currSavingsRate >= 0
            ],
            'period' => $targetMonthLabel
        ];
    }

    /**
     * Build Spending Overview Chart data: Monthly Income vs Expenses for N months.
     */
    private function buildSpendingOverviewData(string $userId, int $monthsCount, ?string $endMonthKey = null): array
    {
        $txCol = Database::getCollection('transactions');

        // Reference end timestamp
        $endTs = $endMonthKey ? strtotime($endMonthKey . '-01') : time();
        if (!$endTs) {
            $endTs = time();
        }

        // Generate past N months array chronologically (e.g. Jan -> Jun)
        $monthBuckets = [];
        for ($i = $monthsCount - 1; $i >= 0; $i--) {
            $ts = strtotime("-{$i} month", $endTs);
            $key = date('Y-m', $ts);
            $monthBuckets[$key] = [
                'month' => date('M', $ts),
                'month_key' => $key,
                'year' => date('Y', $ts),
                'income' => 0.0,
                'expenses' => 0.0
            ];
        }

        // Fetch user transactions
        $txs = $txCol->find(['user_fk' => $userId], [
            'projection' => [
                'type' => 1,
                'amount' => 1,
                'date' => 1
            ]
        ]);

        $totalIncome = 0.0;
        $totalExpenses = 0.0;

        foreach ($txs as $tx) {
            $txDate = (string)($tx['date'] ?? '');
            $monthKey = substr($txDate, 0, 7);

            if (isset($monthBuckets[$monthKey])) {
                $type = strtolower(trim((string)($tx['type'] ?? '')));
                $amt = (float)($tx['amount'] ?? 0);

                if ($type === 'income') {
                    $monthBuckets[$monthKey]['income'] += $amt;
                    $totalIncome += $amt;
                } elseif ($type === 'expense') {
                    $monthBuckets[$monthKey]['expenses'] += $amt;
                    $totalExpenses += $amt;
                }
            }
        }

        $series = array_values(array_map(function ($item) {
            $item['income'] = round($item['income'], 2);
            $item['expenses'] = round($item['expenses'], 2);
            $item['net_savings'] = round($item['income'] - $item['expenses'], 2);
            return $item;
        }, $monthBuckets));

        return [
            'chart_data' => $series,
            'summary' => [
                'total_income' => round($totalIncome, 2),
                'total_expenses' => round($totalExpenses, 2),
                'net_savings' => round($totalIncome - $totalExpenses, 2),
                'months_count' => $monthsCount,
                'currency' => '₹'
            ]
        ];
    }

    /**
     * Build Expense Breakdown data: Total spend, category proportions, top categories.
     */
    private function buildExpenseBreakdownData(string $userId, ?string $targetMonth, ?array $dateRange = null): array
    {
        $txCol = Database::getCollection('transactions');
        $categoriesCol = Database::getCollection('categories');

        // Preload categories in a single query
        $userCategories = $categoriesCol->find(['user_fk' => $userId]);
        $categoryDetails = [];
        foreach ($userCategories as $cat) {
            $cid = (string)($cat['_id'] ?? $cat['id'] ?? '');
            if ($cid !== '') {
                $categoryDetails[$cid] = [
                    'name' => $cat['name'] ?? 'Other',
                    'iconName' => $cat['iconName'] ?? $cat['icon'] ?? 'Tag',
                    'color' => $cat['color'] ?? 'blue',
                    'budget' => (float)($cat['budget'] ?? $cat['baseAmount'] ?? 0)
                ];
            }
        }

        // Fetch expense transactions
        $txs = $txCol->find(['user_fk' => $userId, 'type' => 'expense'], [
            'projection' => [
                'category_fk' => 1,
                'amount' => 1,
                'date' => 1
            ]
        ]);

        $catSpend = [];
        $totalExpense = 0.0;

        foreach ($txs as $tx) {
            $txDate = (string)($tx['date'] ?? '');
            $txDateTime = strlen($txDate) === 10 ? $txDate . ' 00:00:00' : $txDate;

            if ($dateRange !== null) {
                if ($txDateTime < $dateRange['start'] || $txDateTime > $dateRange['end']) {
                    continue;
                }
            } elseif ($targetMonth !== null) {
                if (substr($txDate, 0, 7) !== $targetMonth) {
                    continue;
                }
            }

            $amt = (float)($tx['amount'] ?? 0);
            $catId = (string)($tx['category_fk'] ?? 'uncategorized');
            $totalExpense += $amt;

            if (!isset($catSpend[$catId])) {
                $catSpend[$catId] = [
                    'amount' => 0.0,
                    'tx_count' => 0
                ];
            }
            $catSpend[$catId]['amount'] += $amt;
            $catSpend[$catId]['tx_count']++;
        }

        // Format breakdown list sorted descending by spending
        $breakdown = [];
        foreach ($catSpend as $catId => $info) {
            $meta = $categoryDetails[$catId] ?? [
                'name' => ($catId === 'uncategorized') ? 'Uncategorized' : 'Other',
                'iconName' => 'Tag',
                'color' => 'gray',
                'budget' => 0.0
            ];

            $amt = round($info['amount'], 2);
            $pct = $totalExpense > 0 ? round(($amt / $totalExpense) * 100, 1) : 0;
            $colorInfo = $this->resolveCategoryColor($meta['color']);

            $breakdown[] = [
                'category_id' => $catId,
                'name' => $meta['name'],
                'iconName' => $meta['iconName'],
                'color' => $colorInfo['name'],
                'color_hex' => $colorInfo['hex'],
                'budget' => $meta['budget'],
                'amount' => $amt,
                'formatted' => '₹' . number_format($amt, 2),
                'percentage' => $pct,
                'tx_count' => $info['tx_count']
            ];
        }

        usort($breakdown, fn($a, $b) => $b['amount'] <=> $a['amount']);

        $periodLabel = $dateRange ? 'Selected Period' : ($targetMonth ? date('F Y', strtotime($targetMonth . '-01')) : 'All Time');

        return [
            'total_expenses' => round($totalExpense, 2),
            'formatted_total' => '₹' . number_format($totalExpense, 2),
            'currency' => '₹',
            'period' => $periodLabel,
            'categories' => $breakdown
        ];
    }

    /**
     * Build Recent Transactions data enriched with Category and Account details.
     */
    private function buildRecentTransactionsData(string $userId, int $limit): array
    {
        $txCol = Database::getCollection('transactions');
        $categoriesCol = Database::getCollection('categories');
        $accountsCol = Database::getCollection('accounts');

        $transactions = $txCol->find(['user_fk' => $userId], [
            'sort' => ['date' => -1, 'created_at' => -1],
            'limit' => $limit
        ]);

        // Preload user's categories & accounts
        $userCategories = $categoriesCol->find(['user_fk' => $userId]);
        $categoryMap = [];
        foreach ($userCategories as $cat) {
            $cid = (string)($cat['_id'] ?? $cat['id'] ?? '');
            if ($cid !== '') {
                $categoryMap[$cid] = [
                    'name' => $cat['name'] ?? null,
                    'iconName' => $cat['iconName'] ?? $cat['icon'] ?? 'Tag',
                    'color' => $cat['color'] ?? 'blue'
                ];
            }
        }

        $userAccounts = $accountsCol->find(['user_fk' => $userId]);
        $accountMap = [];
        foreach ($userAccounts as $acc) {
            $aid = (string)($acc['_id'] ?? $acc['id'] ?? '');
            if ($aid !== '') {
                $accountMap[$aid] = [
                    'title' => $acc['title'] ?? null,
                    'type' => $acc['type'] ?? null
                ];
            }
        }

        $enriched = [];
        foreach ($transactions as $tx) {
            $amt = (float)($tx['amount'] ?? 0);
            $type = strtolower((string)($tx['type'] ?? 'expense'));
            $catId = (string)($tx['category_fk'] ?? '');
            $accId = (string)($tx['account_fk'] ?? '');

            $catInfo = $categoryMap[$catId] ?? null;
            $accInfo = $accountMap[$accId] ?? null;

            $enriched[] = [
                'id' => (string)($tx['_id'] ?? $tx['id'] ?? ''),
                'description' => $tx['description'] ?? '',
                'amount' => $amt,
                'formatted_amount' => ($type === 'income' ? '+₹' : '-₹') . number_format($amt, 2),
                'type' => $type,
                'date' => $tx['date'] ?? '',
                'note' => $tx['note'] ?? null,
                'category_fk' => $catId ?: null,
                'category_name' => $catInfo['name'] ?? null,
                'category_icon' => $catInfo['iconName'] ?? 'Tag',
                'category_color' => $catInfo['color'] ?? 'gray',
                'account_fk' => $accId ?: null,
                'account_title' => $accInfo['title'] ?? ($tx['account'] ?? null),
                'account_type' => $accInfo['type'] ?? null,
                'created_at' => $tx['created_at'] ?? ''
            ];
        }

        return $enriched;
    }

    /**
     * Resolve timeframe options into concrete date ranges for filtering and prior comparisons.
     */
    public function resolveTimeframe(array $params): array
    {
        $rawTf = strtolower(trim((string)($params['timeframe'] ?? '')));
        $timeframe = str_replace(['-', ' '], '_', $rawTf);

        // Custom Date Range: ?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
        if (!empty($params['start_date']) && !empty($params['end_date'])) {
            $startDate = substr(trim((string)$params['start_date']), 0, 10);
            $endDate = substr(trim((string)$params['end_date']), 0, 10);
            $startTs = strtotime($startDate . ' 00:00:00');
            $endTs = strtotime($endDate . ' 23:59:59');

            if ($startTs && $endTs && $startTs <= $endTs) {
                $duration = $endTs - $startTs + 1;
                $priorEndTs = $startTs - 1;
                $priorStartTs = $priorEndTs - $duration + 1;

                return [
                    'key' => 'custom',
                    'label' => date('M j, Y', $startTs) . ' - ' . date('M j, Y', $endTs),
                    'start' => date('Y-m-d 00:00:00', $startTs),
                    'end' => date('Y-m-d 23:59:59', $endTs),
                    'prior_start' => date('Y-m-d 00:00:00', $priorStartTs),
                    'prior_end' => date('Y-m-d 23:59:59', $priorEndTs),
                    'prior_label' => 'vs prior period (' . date('M j, Y', $priorStartTs) . ' - ' . date('M j, Y', $priorEndTs) . ')',
                    'months_count' => max(1, min(24, (int)ceil($duration / (86400 * 30))))
                ];
            }
        }

        // Custom Specific Month: ?month=YYYY-MM
        if (!empty($params['month']) && preg_match('/^\d{4}-\d{2}$/', trim((string)$params['month']))) {
            $monthStr = trim((string)$params['month']);
            $startTs = strtotime($monthStr . '-01 00:00:00');
            $endTs = strtotime(date('Y-m-t 23:59:59', $startTs));
            $priorStartTs = strtotime('-1 month', $startTs);
            $priorEndTs = strtotime(date('Y-m-t 23:59:59', $priorStartTs));

            return [
                'key' => 'month',
                'label' => date('F Y', $startTs),
                'start' => date('Y-m-01 00:00:00', $startTs),
                'end' => date('Y-m-t 23:59:59', $startTs),
                'prior_start' => date('Y-m-01 00:00:00', $priorStartTs),
                'prior_end' => date('Y-m-t 23:59:59', $priorStartTs),
                'prior_label' => 'vs prior month (' . date('F Y', $priorStartTs) . ')',
                'months_count' => 6
            ];
        }

        switch ($timeframe) {
            case 'last_month':
            case 'lastmonth':
                $startTs = strtotime('first day of last month 00:00:00');
                $endTs = strtotime('last day of last month 23:59:59');
                $priorStartTs = strtotime('-1 month', $startTs);
                $priorEndTs = strtotime(date('Y-m-t 23:59:59', $priorStartTs));

                return [
                    'key' => 'last_month',
                    'label' => 'Last Month (' . date('F Y', $startTs) . ')',
                    'start' => date('Y-m-01 00:00:00', $startTs),
                    'end' => date('Y-m-t 23:59:59', $startTs),
                    'prior_start' => date('Y-m-01 00:00:00', $priorStartTs),
                    'prior_end' => date('Y-m-t 23:59:59', $priorStartTs),
                    'prior_label' => 'vs prior period (' . date('F Y', $priorStartTs) . ')',
                    'months_count' => 6
                ];

            case 'last_3_months':
            case 'last3months':
            case '3_months':
            case 'last3m':
                $startTs = strtotime('-2 months', strtotime(date('Y-m-01 00:00:00')));
                $endTs = strtotime(date('Y-m-t 23:59:59'));
                $priorEndTs = $startTs - 1;
                $priorStartTs = strtotime('-3 months', $startTs);

                return [
                    'key' => 'last_3_months',
                    'label' => 'Last 3 Months (' . date('M Y', $startTs) . ' - ' . date('M Y', $endTs) . ')',
                    'start' => date('Y-m-01 00:00:00', $startTs),
                    'end' => date('Y-m-t 23:59:59', $endTs),
                    'prior_start' => date('Y-m-01 00:00:00', $priorStartTs),
                    'prior_end' => date('Y-m-d 23:59:59', $priorEndTs),
                    'prior_label' => 'vs prior period (' . date('M Y', $priorStartTs) . ' - ' . date('M Y', $priorEndTs) . ')',
                    'months_count' => 6
                ];

            case 'this_year':
            case 'thisyear':
            case 'year':
                $year = date('Y');
                $prevYear = (string)((int)$year - 1);

                return [
                    'key' => 'this_year',
                    'label' => 'This Year (' . $year . ')',
                    'start' => $year . '-01-01 00:00:00',
                    'end' => $year . '-12-31 23:59:59',
                    'prior_start' => $prevYear . '-01-01 00:00:00',
                    'prior_end' => $prevYear . '-12-31 23:59:59',
                    'prior_label' => 'vs prior year (' . $prevYear . ')',
                    'months_count' => 12
                ];

            case 'this_month':
            case 'thismonth':
            default:
                $startTs = strtotime(date('Y-m-01 00:00:00'));
                $endTs = strtotime(date('Y-m-t 23:59:59'));
                $priorStartTs = strtotime('-1 month', $startTs);
                $priorEndTs = strtotime(date('Y-m-t 23:59:59', $priorStartTs));

                return [
                    'key' => 'this_month',
                    'label' => 'This Month (' . date('F Y') . ')',
                    'start' => date('Y-m-01 00:00:00'),
                    'end' => date('Y-m-t 23:59:59'),
                    'prior_start' => date('Y-m-01 00:00:00', $priorStartTs),
                    'prior_end' => date('Y-m-t 23:59:59', $priorStartTs),
                    'prior_label' => 'vs prior period (' . date('F Y', $priorStartTs) . ')',
                    'months_count' => 6
                ];
        }
    }

    /**
     * Map category color strings to consistent hex palette codes.
     */
    private function resolveCategoryColor(?string $color): array
    {
        $colorMap = [
            'green' => ['name' => 'green', 'hex' => '#10B981'],
            'emerald' => ['name' => 'emerald', 'hex' => '#10B981'],
            'blue' => ['name' => 'blue', 'hex' => '#3B82F6'],
            'indigo' => ['name' => 'indigo', 'hex' => '#6366F1'],
            'purple' => ['name' => 'purple', 'hex' => '#8B5CF6'],
            'violet' => ['name' => 'violet', 'hex' => '#8B5CF6'],
            'pink' => ['name' => 'pink', 'hex' => '#EC4899'],
            'rose' => ['name' => 'rose', 'hex' => '#F43F5E'],
            'amber' => ['name' => 'amber', 'hex' => '#F59E0B'],
            'yellow' => ['name' => 'yellow', 'hex' => '#EAB308'],
            'orange' => ['name' => 'orange', 'hex' => '#F97316'],
            'cyan' => ['name' => 'cyan', 'hex' => '#06B6D4'],
            'teal' => ['name' => 'teal', 'hex' => '#14B8A6'],
            'gray' => ['name' => 'gray', 'hex' => '#6B7280'],
        ];

        $c = strtolower(trim((string)$color));
        if (isset($colorMap[$c])) {
            return $colorMap[$c];
        }
        if (str_starts_with($c, '#')) {
            return ['name' => 'custom', 'hex' => $c];
        }
        return ['name' => $c ?: 'blue', 'hex' => '#3B82F6'];
    }

    /**
     * Compute rounded percentage change between two numbers.
     */
    private function calculatePercentageChange(float $current, float $previous): float
    {
        if ($previous == 0.0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        $change = (($current - $previous) / abs($previous)) * 100;
        return round($change, 1);
    }
}
