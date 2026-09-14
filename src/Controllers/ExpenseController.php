<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Database;
use App\Helpers\ResponseHelper;
use PDO;

class ExpenseController
{
    private array $defaultCategories = [
        'Food & Dining',
        'Transportation',
        'Utilities & Bills',
        'Entertainment',
        'Salary/Income',
        'Investment',
        'Miscellaneous'
    ];

    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $queryParams = $request->getQueryParams();

        $search = trim($queryParams['search'] ?? '');
        $category = trim($queryParams['category'] ?? '');
        $type = trim(strtolower($queryParams['type'] ?? ''));
        $fromDate = trim($queryParams['from_date'] ?? '');
        $toDate = trim($queryParams['to_date'] ?? '');
        $sortBy = trim(strtolower($queryParams['sort_by'] ?? 'date'));
        $sortOrder = strtoupper($queryParams['sort_order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        $allowedSortFields = [
            'date' => 'date',
            'amount' => 'amount',
            'title' => 'title',
            'created_at' => 'created_at'
        ];

        $sortField = $allowedSortFields[$sortBy] ?? 'date';

        try {
            $pdo = Database::getConnection();

            $sql = "SELECT id, user_id, title, amount, type, category, date, notes, created_at, updated_at FROM expenses WHERE user_id = ?";
            $params = [$user['id']];

            if (!empty($search)) {
                $sql .= " AND (title LIKE ? OR notes LIKE ?)";
                $params[] = "%{$search}%";
                $params[] = "%{$search}%";
            }

            if (!empty($category)) {
                $sql .= " AND category = ?";
                $params[] = $category;
            }

            if (!empty($type) && in_array($type, ['income', 'expense'])) {
                $sql .= " AND type = ?";
                $params[] = $type;
            }

            if (!empty($fromDate)) {
                $sql .= " AND date >= ?";
                $params[] = $fromDate;
            }

            if (!empty($toDate)) {
                $sql .= " AND date <= ?";
                $params[] = $toDate;
            }

            $sql .= " ORDER BY {$sortField} {$sortOrder}, id DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $expenses = $stmt->fetchAll();

            // Cast numerical values correctly
            $formattedExpenses = array_map(function($item) {
                $item['id'] = (int)$item['id'];
                $item['user_id'] = (int)$item['user_id'];
                $item['amount'] = (float)$item['amount'];
                return $item;
            }, $expenses);

            return ResponseHelper::success($response, [
                'expenses' => $formattedExpenses,
                'total_count' => count($formattedExpenses)
            ], 'Expenses retrieved successfully');

        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to retrieve expenses: ' . $e->getMessage(), 500);
        }
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id = (int)$args['id'];

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT id, user_id, title, amount, type, category, date, notes, created_at, updated_at FROM expenses WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $user['id']]);
            $expense = $stmt->fetch();

            if (!$expense) {
                return ResponseHelper::error($response, 'Expense record not found', 404);
            }

            $expense['id'] = (int)$expense['id'];
            $expense['user_id'] = (int)$expense['user_id'];
            $expense['amount'] = (float)$expense['amount'];

            return ResponseHelper::success($response, ['expense' => $expense], 'Expense details retrieved');

        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Error fetching expense details: ' . $e->getMessage(), 500);
        }
    }

    public function create(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $body = (array) $request->getParsedBody();

        $title = trim($body['title'] ?? '');
        $amount = isset($body['amount']) ? (float)$body['amount'] : 0.0;
        $type = trim(strtolower($body['type'] ?? 'expense'));
        $category = trim($body['category'] ?? 'Miscellaneous');
        $date = trim($body['date'] ?? date('Y-m-d'));
        $notes = isset($body['notes']) ? trim($body['notes']) : null;

        $errors = [];
        if (empty($title)) {
            $errors['title'] = 'Title is required';
        }
        if ($amount <= 0) {
            $errors['amount'] = 'Amount must be greater than 0';
        }
        if (!in_array($type, ['income', 'expense'])) {
            $errors['type'] = 'Type must be either "income" or "expense"';
        }
        if (empty($date) || !strtotime($date)) {
            $errors['date'] = 'A valid date (YYYY-MM-DD) is required';
        }

        if (!empty($errors)) {
            return ResponseHelper::error($response, 'Validation failed', 422, $errors);
        }

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("
                INSERT INTO expenses (user_id, title, amount, type, category, date, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$user['id'], $title, $amount, $type, $category, $date, $notes]);

            $id = (int)$pdo->lastInsertId();

            $newExpense = [
                'id' => $id,
                'user_id' => (int)$user['id'],
                'title' => $title,
                'amount' => $amount,
                'type' => $type,
                'category' => $category,
                'date' => $date,
                'notes' => $notes,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            return ResponseHelper::success($response, ['expense' => $newExpense], 'Expense created successfully', 201);

        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to create expense: ' . $e->getMessage(), 500);
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id = (int)$args['id'];
        $body = (array) $request->getParsedBody();

        try {
            $pdo = Database::getConnection();

            // Verify existence and ownership
            $checkStmt = $pdo->prepare("SELECT id FROM expenses WHERE id = ? AND user_id = ?");
            $checkStmt->execute([$id, $user['id']]);
            if (!$checkStmt->fetch()) {
                return ResponseHelper::error($response, 'Expense record not found or access denied', 404);
            }

            $title = trim($body['title'] ?? '');
            $amount = isset($body['amount']) ? (float)$body['amount'] : 0.0;
            $type = trim(strtolower($body['type'] ?? 'expense'));
            $category = trim($body['category'] ?? 'Miscellaneous');
            $date = trim($body['date'] ?? date('Y-m-d'));
            $notes = isset($body['notes']) ? trim($body['notes']) : null;

            $errors = [];
            if (empty($title)) {
                $errors['title'] = 'Title is required';
            }
            if ($amount <= 0) {
                $errors['amount'] = 'Amount must be greater than 0';
            }
            if (!in_array($type, ['income', 'expense'])) {
                $errors['type'] = 'Type must be either "income" or "expense"';
            }
            if (empty($date) || !strtotime($date)) {
                $errors['date'] = 'A valid date (YYYY-MM-DD) is required';
            }

            if (!empty($errors)) {
                return ResponseHelper::error($response, 'Validation failed', 422, $errors);
            }

            $updatedAt = date('Y-m-d H:i:s');
            $updateStmt = $pdo->prepare("
                UPDATE expenses 
                SET title = ?, amount = ?, type = ?, category = ?, date = ?, notes = ?, updated_at = ?
                WHERE id = ? AND user_id = ?
            ");
            $updateStmt->execute([$title, $amount, $type, $category, $date, $notes, $updatedAt, $id, $user['id']]);

            $updatedExpense = [
                'id' => $id,
                'user_id' => (int)$user['id'],
                'title' => $title,
                'amount' => $amount,
                'type' => $type,
                'category' => $category,
                'date' => $date,
                'notes' => $notes,
                'updated_at' => $updatedAt
            ];

            return ResponseHelper::success($response, ['expense' => $updatedExpense], 'Expense updated successfully');

        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to update expense: ' . $e->getMessage(), 500);
        }
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id = (int)$args['id'];

        try {
            $pdo = Database::getConnection();
            
            $checkStmt = $pdo->prepare("SELECT id FROM expenses WHERE id = ? AND user_id = ?");
            $checkStmt->execute([$id, $user['id']]);
            if (!$checkStmt->fetch()) {
                return ResponseHelper::error($response, 'Expense record not found or access denied', 404);
            }

            $deleteStmt = $pdo->prepare("DELETE FROM expenses WHERE id = ? AND user_id = ?");
            $deleteStmt->execute([$id, $user['id']]);

            return ResponseHelper::success($response, null, 'Expense deleted successfully');

        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to delete expense: ' . $e->getMessage(), 500);
        }
    }

    public function summary(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');

        try {
            $pdo = Database::getConnection();

            // Total Income
            $stmtIncome = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE user_id = ? AND type = 'income'");
            $stmtIncome->execute([$user['id']]);
            $totalIncome = (float)$stmtIncome->fetchColumn();

            // Total Expense
            $stmtExpense = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE user_id = ? AND type = 'expense'");
            $stmtExpense->execute([$user['id']]);
            $totalExpense = (float)$stmtExpense->fetchColumn();

            $netBalance = $totalIncome - $totalExpense;

            // Total transactions count
            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE user_id = ?");
            $stmtCount->execute([$user['id']]);
            $totalTransactions = (int)$stmtCount->fetchColumn();

            // Highest single expense
            $stmtHighest = $pdo->prepare("SELECT COALESCE(MAX(amount), 0) FROM expenses WHERE user_id = ? AND type = 'expense'");
            $stmtHighest->execute([$user['id']]);
            $highestExpense = (float)$stmtHighest->fetchColumn();

            // Category breakdown (for expenses)
            $stmtCat = $pdo->prepare("
                SELECT category, COALESCE(SUM(amount), 0) as category_total 
                FROM expenses 
                WHERE user_id = ? AND type = 'expense' 
                GROUP BY category
                ORDER BY category_total DESC
            ");
            $stmtCat->execute([$user['id']]);
            $rawCategories = $stmtCat->fetchAll();

            $categoryBreakdown = [];
            foreach ($rawCategories as $catRow) {
                $catTotal = (float)$catRow['category_total'];
                $percentage = $totalExpense > 0 ? round(($catTotal / $totalExpense) * 100, 2) : 0.0;

                $categoryBreakdown[] = [
                    'category' => $catRow['category'],
                    'total' => $catTotal,
                    'percentage' => $percentage
                ];
            }

            return ResponseHelper::success($response, [
                'total_balance' => $netBalance,
                'total_income' => $totalIncome,
                'total_expense' => $totalExpense,
                'total_transactions' => $totalTransactions,
                'highest_expense' => $highestExpense,
                'category_breakdown' => $categoryBreakdown
            ], 'Financial summary retrieved');

        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to calculate financial summary: ' . $e->getMessage(), 500);
        }
    }
}
