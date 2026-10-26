<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Database;
use App\Helpers\ResponseHelper;
use App\Helpers\UuidHelper;

class TransactionController
{
    private const ALLOWED_TYPES = ['expense', 'income'];

    private function getUserId(Request $request): string
    {
        $user = $request->getAttribute('user');
        return (string)($user['id'] ?? '');
    }

    /**
     * List transactions for authenticated user.
     * Supports query params: type, category_fk, account_fk, search
     */
    public function index(Request $request, Response $response): Response
    {
        $userId = $this->getUserId($request);
        $params = $request->getQueryParams();

        $type = isset($params['type']) ? strtolower(trim($params['type'])) : null;
        $categoryFk = isset($params['category_fk']) ? trim($params['category_fk']) : null;
        $accountFk = isset($params['account_fk']) ? trim($params['account_fk']) : null;
        $search = isset($params['search']) ? trim($params['search']) : null;

        try {
            $txCol = Database::getCollection('transactions');
            $categoriesCol = Database::getCollection('categories');
            $accountsCol = Database::getCollection('accounts');

            $filter = ['user_fk' => $userId];

            if (!empty($type)) {
                $filter['type'] = $type;
            }
            if (!empty($categoryFk)) {
                $filter['category_fk'] = $categoryFk;
            }
            if (!empty($accountFk)) {
                $filter['account_fk'] = $accountFk;
            }
            if (!empty($search)) {
                $filter['description'] = ['$regex' => $search, '$options' => 'i'];
            }

            $transactions = $txCol->find($filter, ['sort' => ['date' => -1, 'created_at' => -1]]);

            // Pre-fetch all user categories and accounts once to eliminate N+1 database queries
            $userCategories = $categoriesCol->find(['user_fk' => $userId]);
            $categoryMap = [];
            foreach ($userCategories as $cat) {
                $cId = (string)($cat['_id'] ?? $cat['id'] ?? '');
                if ($cId !== '') {
                    $categoryMap[$cId] = $cat['name'] ?? null;
                }
            }

            $userAccounts = $accountsCol->find(['user_fk' => $userId]);
            $accountMap = [];
            foreach ($userAccounts as $acc) {
                $aId = (string)($acc['_id'] ?? $acc['id'] ?? '');
                if ($aId !== '') {
                    $accountMap[$aId] = [
                        'title' => $acc['title'] ?? null,
                        'type' => $acc['type'] ?? null,
                    ];
                }
            }

            // Enrich transactions with O(1) in-memory lookups
            foreach ($transactions as &$tx) {
                $tx['amount'] = (float)$tx['amount'];

                $catId = (string)($tx['category_fk'] ?? '');
                $tx['category_name'] = ($catId !== '' && isset($categoryMap[$catId])) ? $categoryMap[$catId] : null;

                $accId = (string)($tx['account_fk'] ?? '');
                if ($accId !== '' && isset($accountMap[$accId])) {
                    $tx['account_title'] = $accountMap[$accId]['title'];
                    $tx['account_type'] = $accountMap[$accId]['type'];
                } else {
                    $tx['account_title'] = null;
                    $tx['account_type'] = null;
                }
            }

            return ResponseHelper::success($response, $transactions, 'Transactions retrieved successfully');
        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to fetch transactions: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get transaction by ID.
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $userId = $this->getUserId($request);
        $transactionId = $args['id'] ?? '';

        try {
            $txCol = Database::getCollection('transactions');
            $categoriesCol = Database::getCollection('categories');
            $accountsCol = Database::getCollection('accounts');

            $tx = $txCol->findOne(['_id' => $transactionId, 'user_fk' => $userId]);

            if (!$tx) {
                return ResponseHelper::error($response, 'Transaction not found', 404);
            }

            $tx['amount'] = (float)$tx['amount'];

            if (!empty($tx['category_fk'])) {
                $cat = $categoriesCol->findOne(['_id' => $tx['category_fk']]);
                $tx['category_name'] = $cat ? $cat['name'] : null;
            }
            if (!empty($tx['account_fk'])) {
                $acc = $accountsCol->findOne(['_id' => $tx['account_fk']]);
                $tx['account_title'] = $acc ? $acc['title'] : null;
                $tx['account_type'] = $acc ? $acc['type'] : null;
            }

            return ResponseHelper::success($response, $tx, 'Transaction details fetched');
        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to fetch transaction: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Create a new transaction.
     */
    public function create(Request $request, Response $response): Response
    {
        $userId = $this->getUserId($request);
        $body = (array) $request->getParsedBody();

        $categoryFk = !empty($body['category_fk']) ? trim($body['category_fk']) : null;
        $accountFk = !empty($body['account_fk']) ? trim($body['account_fk']) : null;
        $description = isset($body['description']) ? trim($body['description']) : null;
        $accountStr = isset($body['account']) ? trim($body['account']) : null;
        $date = !empty($body['date']) ? trim($body['date']) : date('Y-m-d H:i:s');
        $note = isset($body['note']) ? trim($body['note']) : (isset($body['Note']) ? trim($body['Note']) : null);
        $amount = isset($body['amount']) ? (float)$body['amount'] : 0.00;
        $type = isset($body['type']) ? strtolower(trim($body['type'])) : 'expense';

        $errors = [];
        if ($amount <= 0) {
            $errors['amount'] = 'Amount must be greater than 0';
        }
        if (!in_array($type, self::ALLOWED_TYPES)) {
            $errors['type'] = 'Invalid transaction type. Allowed: expense, income';
        }

        if (!empty($errors)) {
            return ResponseHelper::error($response, 'Validation error', 422, $errors);
        }

        try {
            $txCol = Database::getCollection('transactions');
            $categoriesCol = Database::getCollection('categories');
            $accountsCol = Database::getCollection('accounts');

            // Validate category_fk if provided
            if ($categoryFk) {
                $cat = $categoriesCol->findOne(['_id' => $categoryFk, 'user_fk' => $userId]);
                if (!$cat) {
                    return ResponseHelper::error($response, 'Invalid category_fk or category does not belong to user', 422);
                }
            }

            // Validate account_fk if provided
            if ($accountFk) {
                $acc = $accountsCol->findOne(['_id' => $accountFk, 'user_fk' => $userId]);
                if (!$acc) {
                    return ResponseHelper::error($response, 'Invalid account_fk or account does not belong to user', 422);
                }
                if (empty($accountStr)) {
                    $accountStr = $acc['title'];
                }
            }

            $id = UuidHelper::generate();

            $doc = [
                '_id' => $id,
                'id' => $id,
                'user_fk' => $userId,
                'category_fk' => $categoryFk,
                'account_fk' => $accountFk,
                'description' => $description,
                'account' => $accountStr,
                'date' => $date,
                'note' => $note,
                'amount' => $amount,
                'type' => $type,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $txCol->insertOne($doc);

            // Update account balance
            if ($accountFk) {
                $change = ($type === 'expense') ? -$amount : $amount;
                $accountsCol->updateOne(['_id' => $accountFk], ['$inc' => ['amount' => $change]]);
            }

            return ResponseHelper::success($response, $doc, 'Transaction created successfully', 201);
        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to create transaction: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update an existing transaction.
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $userId = $this->getUserId($request);
        $transactionId = $args['id'] ?? '';
        $body = (array) $request->getParsedBody();

        try {
            $txCol = Database::getCollection('transactions');
            $existing = $txCol->findOne(['_id' => $transactionId, 'user_fk' => $userId]);

            if (!$existing) {
                return ResponseHelper::error($response, 'Transaction not found', 404);
            }

            $categoryFk = array_key_exists('category_fk', $body) ? trim((string)$body['category_fk']) : $existing['category_fk'];
            $accountFk = array_key_exists('account_fk', $body) ? trim((string)$body['account_fk']) : $existing['account_fk'];
            $description = array_key_exists('description', $body) ? trim((string)$body['description']) : $existing['description'];
            $accountStr = array_key_exists('account', $body) ? trim((string)$body['account']) : $existing['account'];
            $date = array_key_exists('date', $body) ? trim((string)$body['date']) : $existing['date'];
            $note = array_key_exists('note', $body) ? trim((string)$body['note']) : (array_key_exists('Note', $body) ? trim((string)$body['Note']) : $existing['note']);
            $amount = array_key_exists('amount', $body) ? (float)$body['amount'] : (float)$existing['amount'];
            $type = array_key_exists('type', $body) ? strtolower(trim((string)$body['type'])) : $existing['type'];

            if (!in_array($type, self::ALLOWED_TYPES)) {
                return ResponseHelper::error($response, 'Invalid type. Allowed: expense, income', 422);
            }

            $updateData = [
                'category_fk' => $categoryFk ?: null,
                'account_fk' => $accountFk ?: null,
                'description' => $description,
                'account' => $accountStr,
                'date' => $date,
                'note' => $note,
                'amount' => $amount,
                'type' => $type
            ];

            $txCol->updateOne(['_id' => $transactionId, 'user_fk' => $userId], ['$set' => $updateData]);

            $updatedTx = array_merge(['_id' => $transactionId, 'id' => $transactionId, 'user_fk' => $userId], $updateData);

            return ResponseHelper::success($response, $updatedTx, 'Transaction updated successfully');
        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to update transaction: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete a transaction.
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $userId = $this->getUserId($request);
        $transactionId = $args['id'] ?? '';

        try {
            $txCol = Database::getCollection('transactions');

            $existing = $txCol->findOne(['_id' => $transactionId, 'user_fk' => $userId]);
            if (!$existing) {
                return ResponseHelper::error($response, 'Transaction not found', 404);
            }

            $txCol->deleteOne(['_id' => $transactionId, 'user_fk' => $userId]);

            return ResponseHelper::success($response, null, 'Transaction deleted successfully');
        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to delete transaction: ' . $e->getMessage(), 500);
        }
    }
}
