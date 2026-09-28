<?php declare(strict_types=1);

namespace App\Routes;

use App\Database;
use App\Eventos;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

// Usuarios: personas registradas con nombre + clave publica.
// Un usuario puede ser member de varias cajas (members sigue igual).
final class UsuarioRoutes
{
    public static function register(App $app): void
    {
        // POST /usuarios — {nombre, public_key} -> 201 {ok: true, id}
        // Si la clave ya esta registrada, devuelve el mismo id (200).
        $app->post('/usuarios', function (Request $request, Response $response): Response {
            $body = json_decode((string) $request->getBody(), true) ?? [];
            $nombre = trim((string) ($body['nombre'] ?? ''));
            $publicKey = trim((string) ($body['public_key'] ?? ''));

            if ($nombre === '' || $publicKey === '') {
                return self::jsonError($response, 400, 'missing_fields');
            }

            $pdo = Database::connection();

            $existe = self::buscarPorClave($pdo, $publicKey);
            if ($existe) {
                $response->getBody()->write(json_encode([
                    'ok' => true,
                    'id' => $existe['id'],
                    'ya_existia' => true,
                ]));

                return $response;
            }

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_crear_usuario(?, ?, @id)');
                $stmt->execute([$nombre, $publicKey]);
                $stmt->closeCursor();
                $id = $pdo->query('SELECT @id AS id')->fetch()['id'];
            } else {
                $stmt = $pdo->prepare('INSERT INTO usuarios (nombre, public_key) VALUES (?, ?)');
                $stmt->execute([$nombre, $publicKey]);
                $id = $pdo->lastInsertId();
            }

            Eventos::registrar($pdo, null, 'usuario_creado', [
                'usuario_id' => $id,
                'nombre' => $nombre,
                'public_key' => $publicKey,
            ]);

            $response->getBody()->write(json_encode(['ok' => true, 'id' => $id]));

            return $response->withStatus(201);
        });

        // GET /usuarios/{public_key} -> {ok: true, usuario} | 404
        $app->get('/usuarios/{public_key}', function (Request $request, Response $response, array $args): Response {
            $usuario = self::buscarPorClave(Database::connection(), $args['public_key']);

            if (!$usuario) {
                return self::jsonError($response, 404, 'usuario_not_found');
            }

            $response->getBody()->write(json_encode(['ok' => true, 'usuario' => $usuario]));

            return $response;
        });
    }

    /**
     * @return array<string, mixed>|false
     */
    private static function buscarPorClave(PDO $pdo, string $publicKey)
    {
        if (self::isMysql()) {
            $stmt = $pdo->prepare('CALL sp_obtener_usuario(?)');
            $stmt->execute([$publicKey]);
            $usuario = $stmt->fetch();
            $stmt->closeCursor();

            return $usuario;
        }

        $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE public_key = ?');
        $stmt->execute([$publicKey]);

        return $stmt->fetch();
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
