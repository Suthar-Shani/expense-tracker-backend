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
     * List all categories for the authenticated user.
     */
    public function index(Request $request, Response $response): Response
    {
        $userId = $this->getUserId($request);

        try {
            $categoriesCol = Database::getCollection('categories');
            $categories = $categoriesCol->find(['user_fk' => $userId], ['sort' => ['name' => 1]]);

            return ResponseHelper::success($response, $categories, 'Categories retrieved successfully');
        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to fetch categories: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get category by ID.
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

        if (empty($name)) {
            return ResponseHelper::error($response, 'Validation error', 422, ['name' => 'Category name is required']);
        }

        try {
            $categoriesCol = Database::getCollection('categories');
            
            // Check for duplicate category name for this user
            $existing = $categoriesCol->findOne(['user_fk' => $userId, 'name' => $name]);
            if ($existing) {
                return ResponseHelper::error($response, 'Category with this name already exists', 409);
            }

            $id = UuidHelper::generate();
            $doc = [
                '_id' => $id,
                'id' => $id,
                'name' => $name,
                'user_fk' => $userId,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $categoriesCol->insertOne($doc);

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

        $name = trim($body['name'] ?? '');

        if (empty($name)) {
            return ResponseHelper::error($response, 'Validation error', 422, ['name' => 'Category name is required']);
        }

        try {
            $categoriesCol = Database::getCollection('categories');

            $existing = $categoriesCol->findOne(['_id' => $categoryId, 'user_fk' => $userId]);
            if (!$existing) {
                return ResponseHelper::error($response, 'Category not found', 404);
            }

            $categoriesCol->updateOne(['_id' => $categoryId, 'user_fk' => $userId], [
                '$set' => ['name' => $name]
            ]);

            $category = [
                '_id' => $categoryId,
                'id' => $categoryId,
                'name' => $name,
                'user_fk' => $userId
            ];

            return ResponseHelper::success($response, $category, 'Category updated successfully');
        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to update category: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete a category.
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $userId = $this->getUserId($request);
        $categoryId = $args['id'] ?? '';

        try {
            $categoriesCol = Database::getCollection('categories');

            $existing = $categoriesCol->findOne(['_id' => $categoryId, 'user_fk' => $userId]);
            if (!$existing) {
                return ResponseHelper::error($response, 'Category not found', 404);
            }

            $categoriesCol->deleteOne(['_id' => $categoryId, 'user_fk' => $userId]);

            return ResponseHelper::success($response, null, 'Category deleted successfully');
        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Failed to delete category: ' . $e->getMessage(), 500);
        }
    }
}
