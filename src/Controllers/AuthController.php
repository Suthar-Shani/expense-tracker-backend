<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Database;
use App\Helpers\ResponseHelper;
use App\Helpers\UuidHelper;
use Firebase\JWT\JWT;

class AuthController
{
    private function generateJwt(array $user): string
    {
        $secretKey = $_ENV['JWT_SECRET'] ?? 'explanse_super_secret_jwt_key_2026_change_in_production';
        $expHours = (int)($_ENV['JWT_EXPIRATION_HOURS'] ?? 72);

        $issuedAt = time();
        $expirationTime = $issuedAt + ($expHours * 3600);

        $payload = [
            'iat' => $issuedAt,
            'exp' => $expirationTime,
            'sub' => [
                'id' => (string)($user['_id'] ?? $user['id']),
                'name' => $user['name'],
                'email' => $user['email']
            ]
        ];

        return JWT::encode($payload, $secretKey, 'HS256');
    }

    public function register(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        $name = trim($body['name'] ?? '');
        $email = trim(strtolower($body['email'] ?? ''));
        $password = $body['password'] ?? '';
        $image = isset($body['image']) ? trim($body['image']) : null;

        $errors = [];
        if (empty($name)) {
            $errors['name'] = 'Name is required';
        }
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Valid email is required';
        }
        if (empty($password) || strlen($password) < 6) {
            $errors['password'] = 'Password must be at least 6 characters long';
        }

        if (!empty($errors)) {
            return ResponseHelper::error($response, 'Validation failed', 422, $errors);
        }

        try {
            $usersCol = Database::getCollection('users');

            // Check existing user in MongoDB
            $existing = $usersCol->findOne(['email' => $email]);
            if ($existing) {
                return ResponseHelper::error($response, 'User with this email already exists', 409);
            }

            $userId = UuidHelper::generate();
            $hash = password_hash($password, PASSWORD_BCRYPT);
            
            $userDoc = [
                '_id' => $userId,
                'id' => $userId,
                'name' => $name,
                'email' => $email,
                'password' => $hash,
                'image' => $image,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $usersCol->insertOne($userDoc);

            $userData = [
                'id' => $userId,
                '_id' => $userId,
                'name' => $name,
                'email' => $email,
                'image' => $image,
                'created_at' => $userDoc['created_at']
            ];

            $token = $this->generateJwt($userData);

            return ResponseHelper::success($response, [
                'user' => $userData,
                'token' => $token
            ], 'User registered successfully', 201);

        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Registration error: ' . $e->getMessage(), 500);
        }
    }

    public function login(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        $email = trim(strtolower($body['email'] ?? ''));
        $password = $body['password'] ?? '';

        if (empty($email) || empty($password)) {
            return ResponseHelper::error($response, 'Email and password are required', 422);
        }

        try {
            $usersCol = Database::getCollection('users');
            $user = $usersCol->findOne(['email' => $email]);

            if (!$user || !password_verify($password, $user['password'])) {
                return ResponseHelper::error($response, 'Invalid email or password', 401);
            }

            $userId = (string)($user['_id'] ?? $user['id']);

            $userData = [
                'id' => $userId,
                '_id' => $userId,
                'name' => $user['name'],
                'email' => $user['email'],
                'image' => $user['image'] ?? null,
                'created_at' => $user['created_at'] ?? date('Y-m-d H:i:s')
            ];

            $token = $this->generateJwt($userData);

            return ResponseHelper::success($response, [
                'user' => $userData,
                'token' => $token
            ], 'Login successful');

        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Login error: ' . $e->getMessage(), 500);
        }
    }

    public function me(Request $request, Response $response): Response
    {
        $userAttr = $request->getAttribute('user');
        $userId = (string)($userAttr['id'] ?? '');

        try {
            $usersCol = Database::getCollection('users');
            $user = $usersCol->findOne(['_id' => $userId]);

            if (!$user) {
                return ResponseHelper::error($response, 'User not found', 404);
            }

            return ResponseHelper::success($response, [
                'user' => [
                    'id' => (string)($user['_id'] ?? $user['id']),
                    '_id' => (string)($user['_id'] ?? $user['id']),
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'image' => $user['image'] ?? null,
                    'created_at' => $user['created_at'] ?? null
                ]
            ], 'Current user profile fetched');

        } catch (\Exception $e) {
            return ResponseHelper::error($response, 'Error fetching user profile: ' . $e->getMessage(), 500);
        }
    }
}
