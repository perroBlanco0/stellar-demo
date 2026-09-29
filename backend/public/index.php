<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\CorsMiddleware;
use App\Database;
use App\Routes\AdminRoutes;
use App\Routes\CajaRoutes;
use App\Routes\ExecuteRoutes;
use App\Routes\FaucetRoutes;
use App\Routes\RecuperacionRoutes;
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
            'ALTER TABLE members ADD COLUMN puede_aprobar INTEGER NOT NULL DEFAULT 1',
            'ALTER TABLE members ADD COLUMN usuario_id INTEGER REFERENCES usuarios(id)',
            'ALTER TABLE admin_users ADD COLUMN rol TEXT NOT NULL DEFAULT \'admin\'',
        ] as $alter) {
            try {
                $pdo->exec($alter);
            } catch (\PDOException $e) {
                // La columna ya existe.
            }
        }
    }
}

// Primer super-admin: se siembra una sola vez desde env y vive en la org "Cosigna".
// SUPER_ADMIN_EMAIL + SUPER_ADMIN_PASSWORD; sin ellas no se crea nada.
$superEmail = strtolower(trim((string) ($_ENV['SUPER_ADMIN_EMAIL'] ?? '')));
$superPass = (string) ($_ENV['SUPER_ADMIN_PASSWORD'] ?? '');
if ($superEmail !== '' && $superPass !== '') {
    $pdo = Database::connection();
    $faltaSuper = (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE rol = 'super'")->fetchColumn() === 0;
    if ($faltaSuper) {
        $stmt = $pdo->prepare('SELECT id FROM organizaciones WHERE nombre = ?');
        $stmt->execute(['Cosigna']);
        $orgId = $stmt->fetchColumn();
        if (!$orgId) {
            $pdo->prepare('INSERT INTO organizaciones (nombre) VALUES (?)')->execute(['Cosigna']);
            $orgId = $pdo->lastInsertId();
        }
        $pdo->prepare('INSERT INTO admin_users (organizacion_id, email, password_hash, rol) VALUES (?, ?, ?, ?)')
            ->execute([$orgId, $superEmail, password_hash($superPass, PASSWORD_DEFAULT), 'super']);
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
RecuperacionRoutes::register($app);
TrazabilidadRoutes::register($app);
AdminRoutes::register($app);

$app->get('/', function (Request $request, Response $response): Response {
    $response->getBody()->write(json_encode(['ok' => true, 'service' => 'stellarbarrio-backend']));

    return $response;
});

$app->run();
