<?php

namespace App\Helpers;

use Psr\Http\Message\ResponseInterface as Response;

class ResponseHelper
{
    public static function json(Response $response, array $data, int $status = 200): Response
    {
        $payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $response->getBody()->write($payload);
        
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    public static function success(Response $response, $data = null, string $message = 'Success', int $status = 200): Response
    {
        return self::json($response, [
            'status' => 'success',
            'message' => $message,
            'data' => $data
        ], $status);
    }

    public static function error(Response $response, string $message = 'Error', int $status = 400, $errors = null): Response
    {
        $payload = [
            'status' => 'error',
            'message' => $message
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return self::json($response, $payload, $status);
    }
}
