<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\CorsMiddleware;
use App\Database;
use App\Routes\AdminRoutes;
use App\Routes\CajaRoutes;
use App\Routes\ExecuteRoutes;
use App\Routes\FaucetRoutes;
use App\Routes\StellarRoutes;
use App\Routes\TrazabilidadRoutes;
use App\Routes\UsuarioRoutes;
use Dotenv\Dotenv;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

$app = AppFactory::create();

$app->add(new CorsMiddleware());

// Aplica el schema idempotente en cada arranque (CREATE TABLE IF NOT EXISTS):
// en Postgres/SQLite crea lo que falte sin tocar lo existente.
// Con MySQL el schema y los SP se corren a mano (procedures.mysql.sql).
$driver = $_ENV['DB_DRIVER'] ?? 'pgsql';
if ($driver !== 'mysql') {
    $schema = $driver === 'sqlite' ? 'schema.sqlite.sql' : 'schema.sql';
    $pdo = Database::connection();
    $pdo->exec(file_get_contents(__DIR__ . '/../' . $schema));

    // SQLite no acepta ADD COLUMN IF NOT EXISTS: migrar a mano.
    // Postgres ya lleva su propio ALTER idempotente dentro de schema.sql.
    if ($driver === 'sqlite') {
        foreach ([
            'ALTER TABLE cajas ADD COLUMN organizacion_id INTEGER REFERENCES organizaciones(id)',
            'ALTER TABLE usuarios ADD COLUMN email TEXT',
            'ALTER TABLE usuarios ADD COLUMN password_hash TEXT',
        ] as $alter) {
            try {
                $pdo->exec($alter);
            } catch (\PDOException $e) {
                // La columna ya existe.
            }
        }
    }
}

// Preflight CORS para todas las rutas.
$app->options('/{routes:.*}', function (Request $request, Response $response): Response {
    return $response;
});

FaucetRoutes::register($app);
CajaRoutes::register($app);
StellarRoutes::register($app);
ExecuteRoutes::register($app);
UsuarioRoutes::register($app);
TrazabilidadRoutes::register($app);
AdminRoutes::register($app);

$app->get('/', function (Request $request, Response $response): Response {
    $response->getBody()->write(json_encode(['ok' => true, 'service' => 'stellarbarrio-backend']));

    return $response;
});

$app->run();
