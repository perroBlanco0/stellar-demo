<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\CorsMiddleware;
use App\Routes\CajaRoutes;
use App\Routes\FaucetRoutes;
use Dotenv\Dotenv;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

$app = AppFactory::create();

$app->add(new CorsMiddleware());

// Preflight CORS para todas las rutas.
$app->options('/{routes:.+}', function (Request $request, Response $response): Response {
    return $response;
});

FaucetRoutes::register($app);
CajaRoutes::register($app);

$app->get('/', function (Request $request, Response $response): Response {
    $response->getBody()->write(json_encode(['ok' => true, 'service' => 'stellarbarrio-backend']));

    return $response;
});

$app->run();
