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

            Eventos::registrar($pdo, null, 'admin_login', [
                'admin_id' => $admin['id'],
                'email' => $admin['email'],
                'organizacion_id' => $admin['organizacion_id'],
            ]);

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

        // GET /organizaciones/{id}/cajas — cajas de la org (solo admin de esa org).
        $app->get('/organizaciones/{id}/cajas', function (Request $request, Response $response, array $args): Response {
            $pdo = Database::connection();

            $admin = Auth::admin($pdo, $request);
            if (!$admin) {
                return self::jsonError($response, 401, 'unauthorized');
            }
            if ((int) $admin['organizacion_id'] !== (int) $args['id']) {
                return self::jsonError($response, 403, 'forbidden');
            }

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_listar_cajas_por_organizacion(?)');
                $stmt->execute([$args['id']]);
                $cajas = $stmt->fetchAll();
                $stmt->closeCursor();
            } else {
                $stmt = $pdo->prepare(
                    'SELECT id, organizacion_id, nombre, curso, public_key, umbral, creado_en
                     FROM cajas WHERE organizacion_id = ? ORDER BY id'
                );
                $stmt->execute([$args['id']]);
                $cajas = $stmt->fetchAll();
            }

            $response->getBody()->write(json_encode(['ok' => true, 'cajas' => $cajas]));

            return $response;
        });

        // PUT /organizaciones/{id} — {nombre} (solo admin de esa org).
        $app->put('/organizaciones/{id}', function (Request $request, Response $response, array $args): Response {
            $body = json_decode((string) $request->getBody(), true) ?? [];
            $nombre = trim((string) ($body['nombre'] ?? ''));

            if ($nombre === '') {
                return self::jsonError($response, 400, 'missing_fields');
            }

            $pdo = Database::connection();

            $admin = Auth::admin($pdo, $request);
            if (!$admin) {
                return self::jsonError($response, 401, 'unauthorized');
            }
            if ((int) $admin['organizacion_id'] !== (int) $args['id']) {
                return self::jsonError($response, 403, 'forbidden');
            }

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_actualizar_organizacion(?, ?)');
                $stmt->execute([$args['id'], $nombre]);
                $stmt->closeCursor();
            } else {
                $pdo->prepare('UPDATE organizaciones SET nombre = ? WHERE id = ?')
                    ->execute([$nombre, $args['id']]);
            }

            Eventos::registrar($pdo, null, 'organizacion_editada', [
                'organizacion_id' => $args['id'],
                'nombre' => $nombre,
            ]);

            $response->getBody()->write(json_encode(['ok' => true]));

            return $response;
        });

        // DELETE /organizaciones/{id} — solo si no tiene cajas.
        // Borra admin_sessions de sus admins, luego admin_users, luego la org.
        $app->delete('/organizaciones/{id}', function (Request $request, Response $response, array $args): Response {
            $pdo = Database::connection();

            $admin = Auth::admin($pdo, $request);
            if (!$admin) {
                return self::jsonError($response, 401, 'unauthorized');
            }
            if ((int) $admin['organizacion_id'] !== (int) $args['id']) {
                return self::jsonError($response, 403, 'forbidden');
            }

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_listar_cajas_por_organizacion(?)');
                $stmt->execute([$args['id']]);
                $tieneCajas = count($stmt->fetchAll()) > 0;
                $stmt->closeCursor();
            } else {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM cajas WHERE organizacion_id = ?');
                $stmt->execute([$args['id']]);
                $tieneCajas = (int) $stmt->fetchColumn() > 0;
            }

            if ($tieneCajas) {
                return self::jsonError($response, 409, 'organizacion_tiene_cajas');
            }

            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    'DELETE FROM admin_sessions WHERE admin_user_id IN
                     (SELECT id FROM admin_users WHERE organizacion_id = ?)'
                )->execute([$args['id']]);
                $pdo->prepare('DELETE FROM admin_users WHERE organizacion_id = ?')
                    ->execute([$args['id']]);

                if (self::isMysql()) {
                    $stmt = $pdo->prepare('CALL sp_eliminar_organizacion(?)');
                    $stmt->execute([$args['id']]);
                    $stmt->closeCursor();
                } else {
                    $pdo->prepare('DELETE FROM organizaciones WHERE id = ?')
                        ->execute([$args['id']]);
                }

                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            Eventos::registrar($pdo, null, 'organizacion_eliminada', [
                'organizacion_id' => $args['id'],
            ]);

            $response->getBody()->write(json_encode(['ok' => true]));

            return $response;
        });

        // PUT /organizaciones/{id}/admins/{adminId} — {password}
        // Cambia el password de un admin de la org (solo admin de esa org).
        $app->put('/organizaciones/{id}/admins/{adminId}', function (Request $request, Response $response, array $args): Response {
            $body = json_decode((string) $request->getBody(), true) ?? [];
            $password = (string) ($body['password'] ?? '');

            if ($password === '') {
                return self::jsonError($response, 400, 'missing_fields');
            }

            $pdo = Database::connection();

            $admin = Auth::admin($pdo, $request);
            if (!$admin) {
                return self::jsonError($response, 401, 'unauthorized');
            }
            if ((int) $admin['organizacion_id'] !== (int) $args['id']) {
                return self::jsonError($response, 403, 'forbidden');
            }

            $stmt = $pdo->prepare('SELECT id FROM admin_users WHERE id = ? AND organizacion_id = ?');
            $stmt->execute([$args['adminId'], $args['id']]);
            if (!$stmt->fetch()) {
                return self::jsonError($response, 404, 'admin_not_found');
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_actualizar_admin(?, ?)');
                $stmt->execute([$args['adminId'], $hash]);
                $stmt->closeCursor();
            } else {
                $pdo->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')
                    ->execute([$hash, $args['adminId']]);
            }

            Eventos::registrar($pdo, null, 'admin_editado', [
                'organizacion_id' => $args['id'],
                'admin_id' => $args['adminId'],
            ]);

            $response->getBody()->write(json_encode(['ok' => true]));

            return $response;
        });

        // DELETE /organizaciones/{id}/admins/{adminId}
        // No deja a la organizacion sin admins (409 ultimo_admin).
        $app->delete('/organizaciones/{id}/admins/{adminId}', function (Request $request, Response $response, array $args): Response {
            $pdo = Database::connection();

            $admin = Auth::admin($pdo, $request);
            if (!$admin) {
                return self::jsonError($response, 401, 'unauthorized');
            }
            if ((int) $admin['organizacion_id'] !== (int) $args['id']) {
                return self::jsonError($response, 403, 'forbidden');
            }

            $stmt = $pdo->prepare('SELECT id FROM admin_users WHERE id = ? AND organizacion_id = ?');
            $stmt->execute([$args['adminId'], $args['id']]);
            if (!$stmt->fetch()) {
                return self::jsonError($response, 404, 'admin_not_found');
            }

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_contar_admins(?)');
                $stmt->execute([$args['id']]);
                $total = (int) $stmt->fetch()['total'];
                $stmt->closeCursor();
            } else {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM admin_users WHERE organizacion_id = ?');
                $stmt->execute([$args['id']]);
                $total = (int) $stmt->fetchColumn();
            }

            if ($total <= 1) {
                return self::jsonError($response, 409, 'ultimo_admin');
            }

            $pdo->prepare('DELETE FROM admin_sessions WHERE admin_user_id = ?')
                ->execute([$args['adminId']]);

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_eliminar_admin(?)');
                $stmt->execute([$args['adminId']]);
                $stmt->closeCursor();
            } else {
                $pdo->prepare('DELETE FROM admin_users WHERE id = ?')
                    ->execute([$args['adminId']]);
            }

            Eventos::registrar($pdo, null, 'admin_eliminado', [
                'organizacion_id' => $args['id'],
                'admin_id' => $args['adminId'],
            ]);

            $response->getBody()->write(json_encode(['ok' => true]));

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
