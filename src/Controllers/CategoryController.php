<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Database;
use App\Helpers\ResponseHelper;
use App\Helpers\UuidHelper;

class CategoryController
{
    private function getUserId(Request $request): string
    {
        $user = $request->getAttribute('user');
        return (string)($user['id'] ?? '');
    }

    /**
     * List all categories for the authenticated user, enriched with stats.
     */
    public function index(Request $request, Response $response): Response
    {
        $userId = $this->getUserId($request);

        try {
            $categoriesCol = Database::getCollection('categories');
            $txCol = Database::getCollection('transactions');

            $categories = $categoriesCol->find(['user_fk' => $userId], ['sort' => ['name' => 1]]);

            // Fetch minimal fields for this user once to compute live stats efficiently
            $transactions = $txCol->find(['user_fk' => $userId], [
                'projection' => [
                    'category_fk' => 1,
                    'type' => 1,
                    'amount' => 1
                ]
            ]);

            $txStatsByCat = [];
            foreach ($transactions as $tx) {
                $catFk = $tx['category_fk'] ?? '';
                if ($catFk === '') {
                    continue;
                }
                if (!isset($txStatsByCat[$catFk])) {
                    $txStatsByCat[$catFk] = [
                        'tx_count' => 0,
                        'total_spent' => 0.0,
                    ];
                }
                $txStatsByCat[$catFk]['tx_count']++;
                if (isset($tx['type']) && strtolower((string)$tx['type']) === 'expense') {
                    $txStatsByCat[$catFk]['total_spent'] += (float)($tx['amount'] ?? 0);
                }
            }

            foreach ($categories as &$cat) {
                $catId = (string)($cat['_id'] ?? $cat['id'] ?? '');
                $budget = (float)($cat['budget'] ?? $cat['baseAmount'] ?? 0);
                $cat['budget'] = $budget;
                $cat['baseAmount'] = $budget; // Backward & frontend compatibility
                $cat['iconName'] = $cat['iconName'] ?? $cat['icon'] ?? 'Utensils';
                $cat['color'] = $cat['color'] ?? 'green';

                $stats = $txStatsByCat[$catId] ?? ['tx_count' => 0, 'total_spent' => 0.0];
                $cat['tx_count'] = $stats['tx_count'];
                $cat['total_spent'] = round($stats['total_spent'], 2);
                $cat['remaining_budget'] = round(max(0, $budget - $stats['total_spent']), 2);
                $cat['spent_percentage'] = $budget > 0 ? round(min(100, ($stats['total_spent'] / $budget) * 100), 1) : 0;
            }

            return ResponseHelper::success($response, $categories, 'Categories retrieved successfully');
        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to fetch categories: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get category by ID with associated statistics.
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $userId = $this->getUserId($request);
        $categoryId = $args['id'] ?? '';

        try {
            $categoriesCol = Database::getCollection('categories');
            $category = $categoriesCol->findOne(['_id' => $categoryId, 'user_fk' => $userId]);

            if (!$category) {
                return ResponseHelper::error($response, 'Category not found', 404);
            }

            // Calculate stats for this category with lightweight projection
            $txCol = Database::getCollection('transactions');
            $transactions = $txCol->find(['user_fk' => $userId, 'category_fk' => $categoryId], [
                'projection' => [
                    'type' => 1,
                    'amount' => 1
                ]
            ]);

            $txCount = count($transactions);
            $totalSpent = 0.0;
            foreach ($transactions as $tx) {
                if (isset($tx['type']) && strtolower((string)$tx['type']) === 'expense') {
                    $totalSpent += (float)($tx['amount'] ?? 0);
                }
            }

            $budget = (float)($category['budget'] ?? $category['baseAmount'] ?? 0);
            $category['budget'] = $budget;
            $category['baseAmount'] = $budget;
            $category['iconName'] = $category['iconName'] ?? $category['icon'] ?? 'Utensils';
            $category['color'] = $category['color'] ?? 'green';
            $category['tx_count'] = $txCount;
            $category['total_spent'] = round($totalSpent, 2);
            $category['remaining_budget'] = round(max(0, $budget - $totalSpent), 2);
            $category['spent_percentage'] = $budget > 0 ? round(min(100, ($totalSpent / $budget) * 100), 1) : 0;

            return ResponseHelper::success($response, $category, 'Category details fetched');
        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to fetch category: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Create a new category.
     */
    public function create(Request $request, Response $response): Response
    {
        $userId = $this->getUserId($request);
        $body = (array) $request->getParsedBody();

        $name = trim($body['name'] ?? '');
        $iconName = trim($body['iconName'] ?? $body['icon'] ?? 'Utensils');
        $color = trim($body['color'] ?? 'green');
        $budget = isset($body['budget']) ? (float)$body['budget'] : (isset($body['baseAmount']) ? (float)$body['baseAmount'] : 0.0);

        if (empty($name)) {
            return ResponseHelper::error($response, 'Validation error', 422, ['name' => 'Category name is required']);
        }

        if (strlen($name) > 100) {
            return ResponseHelper::error($response, 'Validation error', 422, ['name' => 'Category name cannot exceed 100 characters']);
        }

        if ($budget < 0) {
            return ResponseHelper::error($response, 'Validation error', 422, ['budget' => 'Budget amount must be a positive number']);
        }

        try {
            $categoriesCol = Database::getCollection('categories');

            // Case-insensitive duplicate name check for this user
            $existingList = $categoriesCol->find(['user_fk' => $userId]);
            foreach ($existingList as $item) {
                if (strcasecmp($item['name'] ?? '', $name) === 0) {
                    return ResponseHelper::error($response, 'Category with this name already exists', 409);
                }
            }

            $id = UuidHelper::generate();
            $now = date('Y-m-d H:i:s');
            $doc = [
                '_id' => $id,
                'id' => $id,
                'name' => $name,
                'iconName' => !empty($iconName) ? $iconName : 'Utensils',
                'color' => !empty($color) ? $color : 'green',
                'budget' => $budget,
                'baseAmount' => $budget,
                'user_fk' => $userId,
                'created_at' => $now,
                'updated_at' => $now
            ];

            $categoriesCol->insertOne($doc);

            // Add response stats
            $doc['tx_count'] = 0;
            $doc['total_spent'] = 0.0;
            $doc['remaining_budget'] = $budget;
            $doc['spent_percentage'] = 0;

            return ResponseHelper::success($response, $doc, 'Category created successfully', 201);
        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to create category: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update an existing category.
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $userId = $this->getUserId($request);
        $categoryId = $args['id'] ?? '';
        $body = (array) $request->getParsedBody();

        try {
            $categoriesCol = Database::getCollection('categories');

            $existing = $categoriesCol->findOne(['_id' => $categoryId, 'user_fk' => $userId]);
            if (!$existing) {
                return ResponseHelper::error($response, 'Category not found', 404);
            }

            $updates = [];

            if (array_key_exists('name', $body)) {
                $name = trim($body['name']);
                if (empty($name)) {
                    return ResponseHelper::error($response, 'Validation error', 422, ['name' => 'Category name cannot be empty']);
                }
                if (strlen($name) > 100) {
                    return ResponseHelper::error($response, 'Validation error', 422, ['name' => 'Category name cannot exceed 100 characters']);
                }

                // Check duplicate among other categories for this user
                $existingList = $categoriesCol->find(['user_fk' => $userId]);
                foreach ($existingList as $item) {
                    $itemId = (string)($item['_id'] ?? $item['id'] ?? '');
                    if ($itemId !== $categoryId && strcasecmp($item['name'] ?? '', $name) === 0) {
                        return ResponseHelper::error($response, 'Category with this name already exists', 409);
                    }
                }
                $updates['name'] = $name;
            }

            if (array_key_exists('iconName', $body) || array_key_exists('icon', $body)) {
                $iconVal = trim($body['iconName'] ?? $body['icon'] ?? '');
                $updates['iconName'] = !empty($iconVal) ? $iconVal : 'Utensils';
            }

            if (array_key_exists('color', $body)) {
                $colorVal = trim($body['color']);
                $updates['color'] = !empty($colorVal) ? $colorVal : 'green';
            }

            if (array_key_exists('budget', $body) || array_key_exists('baseAmount', $body)) {
                $budgetVal = isset($body['budget']) ? (float)$body['budget'] : (float)$body['baseAmount'];
                if ($budgetVal < 0) {
                    return ResponseHelper::error($response, 'Validation error', 422, ['budget' => 'Budget must be greater than or equal to 0']);
                }
                $updates['budget'] = $budgetVal;
                $updates['baseAmount'] = $budgetVal;
            }

            if (empty($updates)) {
                return ResponseHelper::error($response, 'No valid fields provided for update', 400);
            }

            $updates['updated_at'] = date('Y-m-d H:i:s');

            $categoriesCol->updateOne(['_id' => $categoryId, 'user_fk' => $userId], [
                '$set' => $updates
            ]);

            $updatedDoc = array_merge($existing, $updates);
            $budget = (float)($updatedDoc['budget'] ?? $updatedDoc['baseAmount'] ?? 0);
            $updatedDoc['budget'] = $budget;
            $updatedDoc['baseAmount'] = $budget;
            $updatedDoc['iconName'] = $updatedDoc['iconName'] ?? 'Utensils';
            $updatedDoc['color'] = $updatedDoc['color'] ?? 'green';

            return ResponseHelper::success($response, $updatedDoc, 'Category updated successfully');
        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to update category: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete a category.
     * Prevents deletion if transactions are linked unless ?force=true is supplied.
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $userId = $this->getUserId($request);
        $categoryId = $args['id'] ?? '';
        $queryParams = $request->getQueryParams();
        $force = filter_var($queryParams['force'] ?? false, FILTER_VALIDATE_BOOLEAN);

        try {
            $categoriesCol = Database::getCollection('categories');

            $existing = $categoriesCol->findOne(['_id' => $categoryId, 'user_fk' => $userId]);
            if (!$existing) {
                return ResponseHelper::error($response, 'Category not found', 404);
            }

            $txCol = Database::getCollection('transactions');
            $linkedTx = $txCol->find(['category_fk' => $categoryId, 'user_fk' => $userId]);
            $linkedCount = count($linkedTx);

            if ($linkedCount > 0 && !$force) {
                return ResponseHelper::error(
                    $response,
                    "Cannot delete category: {$linkedCount} transaction(s) are currently associated with it. Please reassign or delete these transactions first, or append '?force=true' to disassociate them and delete.",
                    409,
                    [
                        'linked_transactions_count' => $linkedCount,
                        'category_id' => $categoryId
                    ]
                );
            }

            // If force delete is allowed, unlink the transactions
            if ($linkedCount > 0 && $force) {
                foreach ($linkedTx as $tx) {
                    $txId = (string)($tx['_id'] ?? $tx['id'] ?? '');
                    if (!empty($txId)) {
                        $txCol->updateOne(['_id' => $txId], ['$set' => ['category_fk' => null]]);
                    }
                }
            }

            $categoriesCol->deleteOne(['_id' => $categoryId, 'user_fk' => $userId]);

            return ResponseHelper::success($response, [
                'deleted_category_id' => $categoryId,
                'unlinked_transactions_count' => $force ? $linkedCount : 0
            ], 'Category deleted successfully');
        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to delete category: ' . $e->getMessage(), 500);
        }
    }
}

