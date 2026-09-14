<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Database;
use App\Helpers\ResponseHelper;
use App\Helpers\UuidHelper;

class AccountController
{
    private const ALLOWED_TYPES = ['saveing_ac', 'saving', 'debit', 'credit', 'travel'];
    private const ALLOWED_TITLES = ['bank_ac', 'savings', 'caredit card', 'chash'];

    private function getUserId(Request $request): string
    {
        $user = $request->getAttribute('user');
        return (string)($user['id'] ?? '');
    }

    /**
     * List all accounts for the authenticated user.
     */
    public function index(Request $request, Response $response): Response
    {
        $userId = $this->getUserId($request);

        try {
            $accountsCol = Database::getCollection('accounts');
            $accounts = $accountsCol->find(['user_fk' => $userId]);

            foreach ($accounts as &$acc) {
                $acc['amount'] = (float)$acc['amount'];
            }

            return ResponseHelper::success($response, $accounts, 'Accounts retrieved successfully');
        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to fetch accounts: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get account details by ID.
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $userId = $this->getUserId($request);
        $accountId = $args['id'] ?? '';

        try {
            $accountsCol = Database::getCollection('accounts');
            $account = $accountsCol->findOne(['_id' => $accountId, 'user_fk' => $userId]);

            if (!$account) {
                return ResponseHelper::error($response, 'Account not found', 404);
            }

            $account['amount'] = (float)$account['amount'];

            return ResponseHelper::success($response, $account, 'Account details fetched');
        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to fetch account: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Create a new account.
     */
    public function create(Request $request, Response $response): Response
    {
        $userId = $this->getUserId($request);
        $body = (array) $request->getParsedBody();

        $type = trim($body['type'] ?? '');
        $amount = isset($body['amount']) ? (float)$body['amount'] : 0.00;
        $title = trim($body['title'] ?? '');
        $icons = isset($body['icons']) ? trim($body['icons']) : null;

        $errors = [];
        if (empty($type)) {
            $errors['type'] = 'Account type is required';
        } elseif (!in_array(strtolower($type), self::ALLOWED_TYPES)) {
            $errors['type'] = 'Invalid type. Allowed: ' . implode(', ', self::ALLOWED_TYPES);
        }

        if (empty($title)) {
            $errors['title'] = 'Account title is required';
        } elseif (!in_array(strtolower($title), self::ALLOWED_TITLES)) {
            $errors['title'] = 'Invalid title. Allowed: ' . implode(', ', self::ALLOWED_TITLES);
        }

        if (!empty($errors)) {
            return ResponseHelper::error($response, 'Validation error', 422, $errors);
        }

        try {
            $accountsCol = Database::getCollection('accounts');
            $id = UuidHelper::generate();

            $doc = [
                '_id' => $id,
                'id' => $id,
                'user_fk' => $userId,
                'type' => strtolower($type),
                'amount' => $amount,
                'title' => strtolower($title),
                'icons' => $icons,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $accountsCol->insertOne($doc);

            return ResponseHelper::success($response, $doc, 'Account created successfully', 201);
        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to create account: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update an account.
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $userId = $this->getUserId($request);
        $accountId = $args['id'] ?? '';
        $body = (array) $request->getParsedBody();

        try {
            $accountsCol = Database::getCollection('accounts');

            $existing = $accountsCol->findOne(['_id' => $accountId, 'user_fk' => $userId]);
            if (!$existing) {
                return ResponseHelper::error($response, 'Account not found', 404);
            }

            $type = isset($body['type']) ? trim($body['type']) : $existing['type'];
            $amount = isset($body['amount']) ? (float)$body['amount'] : (float)$existing['amount'];
            $title = isset($body['title']) ? trim($body['title']) : $existing['title'];
            $icons = array_key_exists('icons', $body) ? trim((string)$body['icons']) : ($existing['icons'] ?? null);

            $errors = [];
            if (!in_array(strtolower($type), self::ALLOWED_TYPES)) {
                $errors['type'] = 'Invalid type. Allowed: ' . implode(', ', self::ALLOWED_TYPES);
            }
            if (!in_array(strtolower($title), self::ALLOWED_TITLES)) {
                $errors['title'] = 'Invalid title. Allowed: ' . implode(', ', self::ALLOWED_TITLES);
            }

            if (!empty($errors)) {
                return ResponseHelper::error($response, 'Validation error', 422, $errors);
            }

            $updateData = [
                'type' => strtolower($type),
                'amount' => $amount,
                'title' => strtolower($title),
                'icons' => $icons
            ];

            $accountsCol->updateOne(['_id' => $accountId, 'user_fk' => $userId], ['$set' => $updateData]);

            $updatedAccount = array_merge(['_id' => $accountId, 'id' => $accountId, 'user_fk' => $userId], $updateData);

            return ResponseHelper::success($response, $updatedAccount, 'Account updated successfully');
        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to update account: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete an account.
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $userId = $this->getUserId($request);
        $accountId = $args['id'] ?? '';

        try {
            $accountsCol = Database::getCollection('accounts');

            $existing = $accountsCol->findOne(['_id' => $accountId, 'user_fk' => $userId]);
            if (!$existing) {
                return ResponseHelper::error($response, 'Account not found', 404);
            }

            $accountsCol->deleteOne(['_id' => $accountId, 'user_fk' => $userId]);

            return ResponseHelper::success($response, null, 'Account deleted successfully');
        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to delete account: ' . $e->getMessage(), 500);
        }
    }
}
