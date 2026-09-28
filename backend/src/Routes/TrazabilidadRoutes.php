<?php declare(strict_types=1);

namespace App\Routes;

use App\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

// Trazabilidad: historial de acciones registradas por Eventos.
final class TrazabilidadRoutes
{
    public static function register(App $app): void
    {
        // GET /cajas/{id}/trazabilidad -> {ok: true, eventos: [...]}
        $app->get('/cajas/{id}/trazabilidad', function (Request $request, Response $response, array $args): Response {
            $pdo = Database::connection();

            $caja = $pdo->prepare('SELECT id FROM cajas WHERE id = ?');
            $caja->execute([$args['id']]);
            if (!$caja->fetch()) {
                return self::jsonError($response, 404, 'caja_not_found');
            }

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_listar_eventos(?)');
                $stmt->execute([$args['id']]);
                $eventos = $stmt->fetchAll();
                $stmt->closeCursor();
            } else {
                $stmt = $pdo->prepare('SELECT * FROM eventos WHERE caja_id = ? ORDER BY id DESC');
                $stmt->execute([$args['id']]);
                $eventos = $stmt->fetchAll();
            }

            foreach ($eventos as &$evento) {
                $evento['detalle'] = json_decode((string) $evento['detalle'], true);
            }

            $response->getBody()->write(json_encode(['ok' => true, 'eventos' => $eventos]));

            return $response;
        });
    }

    private static function isMysql(): bool
    {
        return ($_ENV['DB_DRIVER'] ?? 'pgsql') === 'mysql';
    }

    private static function jsonError(Response $response, int $status, string $error): Response
    {
        $response->getBody()->write(json_encode(['ok' => false, 'error' => $error]));

        return $response->withStatus($status);
    }
}
