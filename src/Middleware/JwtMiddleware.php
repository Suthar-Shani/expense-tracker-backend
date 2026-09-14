<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Helpers\ResponseHelper;
use Slim\Psr7\Response as SlimResponse;
use Exception;

class JwtMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (!$authHeader || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $response = new SlimResponse();
            return ResponseHelper::error($response, 'Authorization header (Bearer token) is required', 401);
        }

        $token = $matches[1];
        $secretKey = $_ENV['JWT_SECRET'] ?? 'explanse_super_secret_jwt_key_2026_change_in_production';

        try {
            $decoded = JWT::decode($token, new Key($secretKey, 'HS256'));
            // Attach user data to request attributes
            $request = $request->withAttribute('user', (array) $decoded->sub);
        } catch (Exception $e) {
            $response = new SlimResponse();
            return ResponseHelper::error($response, 'Invalid or expired token: ' . $e->getMessage(), 401);
        }

        return $handler->handle($request);
    }
}
