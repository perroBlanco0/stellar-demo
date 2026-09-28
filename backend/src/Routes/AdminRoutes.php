<?php declare(strict_types=1);

namespace App\Routes;

use App\Auth;
use App\Database;
use App\Eventos;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

// Administracion multi-tenant: organizaciones, sus admins y el login.
// Solo estas rutas piden token; las cajas se leen/operan sin login.
final class AdminRoutes
{
    public static function register(App $app): void
    {
        // POST /auth/login — {email, password} -> {ok, token, organizacion_id}
        $app->post('/auth/login', function (Request $request, Response $response): Response {
            $body = json_decode((string) $request->getBody(), true) ?? [];
            $email = strtolower(trim((string) ($body['email'] ?? '')));
            $password = (string) ($body['password'] ?? '');

            if ($email === '' || $password === '') {
                return self::jsonError($response, 400, 'missing_fields');
            }

            $pdo = Database::connection();

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_obtener_admin_por_email(?)');
                $stmt->execute([$email]);
                $admin = $stmt->fetch();
                $stmt->closeCursor();
            } else {
                $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE email = ?');
                $stmt->execute([$email]);
                $admin = $stmt->fetch();
            }

            if (!$admin || !password_verify($password, $admin['password_hash'])) {
                return self::jsonError($response, 401, 'credenciales_invalidas');
            }

            $token = bin2hex(random_bytes(24));
            $expira = gmdate('Y-m-d H:i:s', time() + 7 * 86400);

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_crear_sesion(?, ?, ?)');
                $stmt->execute([$admin['id'], $token, $expira]);
                $stmt->closeCursor();
            } else {
                $pdo->prepare('INSERT INTO admin_sessions (admin_user_id, token, expira_en) VALUES (?, ?, ?)')
                    ->execute([$admin['id'], $token, $expira]);
            }

            $response->getBody()->write(json_encode([
                'ok' => true,
                'token' => $token,
                'email' => $admin['email'],
                'organizacion_id' => $admin['organizacion_id'],
            ]));

            return $response;
        });

        // POST /organizaciones — {nombre, email, password}
        // Crea la organizacion y su primer admin de una vez (bootstrap abierto).
        $app->post('/organizaciones', function (Request $request, Response $response): Response {
            $body = json_decode((string) $request->getBody(), true) ?? [];
            $nombre = trim((string) ($body['nombre'] ?? ''));
            $email = strtolower(trim((string) ($body['email'] ?? '')));
            $password = (string) ($body['password'] ?? '');

            if ($nombre === '' || $email === '' || $password === '') {
                return self::jsonError($response, 400, 'missing_fields');
            }

            $pdo = Database::connection();

            $stmt = $pdo->prepare('SELECT id FROM admin_users WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                return self::jsonError($response, 409, 'email_ya_registrado');
            }

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_crear_organizacion(?, @oid)');
                $stmt->execute([$nombre]);
                $stmt->closeCursor();
                $orgId = $pdo->query('SELECT @oid AS id')->fetch()['id'];
            } else {
                $pdo->prepare('INSERT INTO organizaciones (nombre) VALUES (?)')->execute([$nombre]);
                $orgId = $pdo->lastInsertId();
            }

            $adminId = self::crearAdmin($pdo, (int) $orgId, $email, $password);

            Eventos::registrar($pdo, null, 'organizacion_creada', [
                'organizacion_id' => $orgId,
                'nombre' => $nombre,
                'admin_id' => $adminId,
                'email' => $email,
            ]);

            $response->getBody()->write(json_encode([
                'ok' => true,
                'id' => $orgId,
                'admin_id' => $adminId,
            ]));

            return $response->withStatus(201);
        });

        // POST /organizaciones/{id}/admins — {email, password}
        // Solo un admin DE ESA organizacion puede agregar otro admin.
        $app->post('/organizaciones/{id}/admins', function (Request $request, Response $response, array $args): Response {
            $pdo = Database::connection();

            $admin = Auth::admin($pdo, $request);
            if (!$admin) {
                return self::jsonError($response, 401, 'unauthorized');
            }
            if ((int) $admin['organizacion_id'] !== (int) $args['id']) {
                return self::jsonError($response, 403, 'forbidden');
            }

            $body = json_decode((string) $request->getBody(), true) ?? [];
            $email = strtolower(trim((string) ($body['email'] ?? '')));
            $password = (string) ($body['password'] ?? '');

            if ($email === '' || $password === '') {
                return self::jsonError($response, 400, 'missing_fields');
            }

            $stmt = $pdo->prepare('SELECT id FROM admin_users WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                return self::jsonError($response, 409, 'email_ya_registrado');
            }

            $adminId = self::crearAdmin($pdo, (int) $args['id'], $email, $password);

            Eventos::registrar($pdo, null, 'admin_creado', [
                'organizacion_id' => $args['id'],
                'admin_id' => $adminId,
                'email' => $email,
            ]);

            $response->getBody()->write(json_encode(['ok' => true, 'id' => $adminId]));

            return $response->withStatus(201);
        });

        // GET /organizaciones/{id}/admins — lista los admins (solo admin de esa org).
        $app->get('/organizaciones/{id}/admins', function (Request $request, Response $response, array $args): Response {
            $pdo = Database::connection();

            $admin = Auth::admin($pdo, $request);
            if (!$admin) {
                return self::jsonError($response, 401, 'unauthorized');
            }
            if ((int) $admin['organizacion_id'] !== (int) $args['id']) {
                return self::jsonError($response, 403, 'forbidden');
            }

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_listar_admins(?)');
                $stmt->execute([$args['id']]);
                $admins = $stmt->fetchAll();
                $stmt->closeCursor();
            } else {
                $stmt = $pdo->prepare(
                    'SELECT id, organizacion_id, email, creado_en FROM admin_users WHERE organizacion_id = ?'
                );
                $stmt->execute([$args['id']]);
                $admins = $stmt->fetchAll();
            }

            $response->getBody()->write(json_encode(['ok' => true, 'admins' => $admins]));

            return $response;
        });
    }

    private static function crearAdmin(PDO $pdo, int $orgId, string $email, string $password): string
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        if (self::isMysql()) {
            $stmt = $pdo->prepare('CALL sp_crear_admin_user(?, ?, ?, @aid)');
            $stmt->execute([$orgId, $email, $hash]);
            $stmt->closeCursor();
            return (string) $pdo->query('SELECT @aid AS id')->fetch()['id'];
        }

        $pdo->prepare('INSERT INTO admin_users (organizacion_id, email, password_hash) VALUES (?, ?, ?)')
            ->execute([$orgId, $email, $hash]);

        return $pdo->lastInsertId();
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
