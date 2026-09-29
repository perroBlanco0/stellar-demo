<?php declare(strict_types=1);

namespace App;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CorsMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $allowedOrigins = array_map('trim', explode(',', $_ENV['CORS_ALLOWED_ORIGIN'] ?? '*'));
        $requestOrigin = $request->getHeaderLine('Origin');

        // En desarrollo aceptamos cualquier puerto de localhost/127.0.0.1
        // (Angular 4200, Vite 4173/5173, etc.) sin tocar la lista en producción.
        $esLocal = (bool) preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $requestOrigin);

        $originToSend = in_array('*', $allowedOrigins, true)
            ? '*'
            : ($esLocal || in_array($requestOrigin, $allowedOrigins, true)
                ? $requestOrigin
                : $allowedOrigins[0]);

        return $response
            ->withHeader('Access-Control-Allow-Origin', $originToSend)
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
            ->withHeader('Content-Type', 'application/json');
    }
}
