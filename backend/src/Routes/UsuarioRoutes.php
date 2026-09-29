<?php declare(strict_types=1);

namespace App\Routes;

use App\Auth;
use App\Correo;
use App\Database;
use App\Eventos;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

// Usuarios: personas registradas con nombre + clave publica.
// Un usuario puede ser member de varias cajas (members sigue igual).
// email + password son opcionales: con ellos el usuario puede hacer login
// (POST /auth/usuario/login) y ver las cajas donde es miembro.
final class UsuarioRoutes
{
    public static function register(App $app): void
    {
        // POST /usuarios — {nombre, public_key, email?, password?} -> 201 {ok: true, id}
        // Si la clave ya esta registrada, devuelve el mismo id (ya_existia: true).
        // Si el email ya esta registrado con otra clave: 409 email_duplicado.
        $app->post('/usuarios', function (Request $request, Response $response): Response {
            $body = json_decode((string) $request->getBody(), true) ?? [];
            $nombre = trim((string) ($body['nombre'] ?? ''));
            $publicKey = trim((string) ($body['public_key'] ?? ''));
            $email = strtolower(trim((string) ($body['email'] ?? '')));
            $password = (string) ($body['password'] ?? '');

            if ($nombre === '' || $publicKey === '') {
                return self::jsonError($response, 400, 'missing_fields');
            }

            $pdo = Database::connection();

            if ($email !== '') {
                $porEmail = self::buscarPorEmail($pdo, $email);
                if ($porEmail && $porEmail['public_key'] !== $publicKey) {
                    return self::jsonError($response, 409, 'email_duplicado');
                }
            }

            $existe = self::buscarPorClave($pdo, $publicKey);
            if ($existe) {
                $response->getBody()->write(json_encode([
                    'ok' => true,
                    'id' => $existe['id'],
                    'ya_existia' => true,
                ]));

                return $response;
            }

            $passwordHash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;
            $emailParam = $email !== '' ? $email : null;

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_crear_usuario(?, ?, ?, ?, @id)');
                $stmt->execute([$nombre, $publicKey, $emailParam, $passwordHash]);
                $stmt->closeCursor();
                $id = $pdo->query('SELECT @id AS id')->fetch()['id'];
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO usuarios (nombre, public_key, email, password_hash) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$nombre, $publicKey, $emailParam, $passwordHash]);
                $id = $pdo->lastInsertId();
            }

            Eventos::registrar($pdo, null, 'usuario_creado', [
                'usuario_id' => $id,
                'nombre' => $nombre,
                'public_key' => $publicKey,
                'email' => $emailParam,
            ]);

            if ($emailParam !== null) {
                Correo::bienvenida($emailParam, $nombre);
            }

            $response->getBody()->write(json_encode(['ok' => true, 'id' => $id]));

            return $response->withStatus(201);
        });

        // GET /usuarios — lista todos los usuarios (cualquier admin).
        $app->get('/usuarios', function (Request $request, Response $response): Response {
            $pdo = Database::connection();

            $admin = Auth::admin($pdo, $request);
            if (!$admin) {
                return self::jsonError($response, 401, 'unauthorized');
            }

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_listar_usuarios()');
                $stmt->execute();
                $usuarios = $stmt->fetchAll();
                $stmt->closeCursor();
            } else {
                $usuarios = $pdo->query(
                    'SELECT id, nombre, public_key, email, creado_en FROM usuarios ORDER BY id'
                )->fetchAll();
            }

            $response->getBody()->write(json_encode(['ok' => true, 'usuarios' => $usuarios]));

            return $response;
        });

        // GET /usuarios/me/cajas — cajas donde el usuario es miembro
        // (match por public_key en members). Bearer token de usuario.
        $app->get('/usuarios/me/cajas', function (Request $request, Response $response): Response {
            $pdo = Database::connection();

            $usuario = Auth::usuario($pdo, $request);
            if (!$usuario) {
                return self::jsonError($response, 401, 'unauthorized');
            }

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_listar_cajas_por_public_key(?)');
                $stmt->execute([$usuario['public_key']]);
                $cajas = $stmt->fetchAll();
                $stmt->closeCursor();
            } else {
                $stmt = $pdo->prepare(
                    'SELECT c.id, c.organizacion_id, c.nombre, c.curso, c.public_key, c.umbral, c.creado_en
                     FROM cajas c
                     JOIN members m ON m.caja_id = c.id
                     WHERE m.public_key = ?
                     ORDER BY c.id'
                );
                $stmt->execute([$usuario['public_key']]);
                $cajas = $stmt->fetchAll();
            }

            $response->getBody()->write(json_encode(['ok' => true, 'cajas' => $cajas]));

            return $response;
        });

        // GET /usuarios/{public_key} -> {ok: true, usuario} | 404
        $app->get('/usuarios/{public_key}', function (Request $request, Response $response, array $args): Response {
            $usuario = self::buscarPorClave(Database::connection(), $args['public_key']);

            if (!$usuario) {
                return self::jsonError($response, 404, 'usuario_not_found');
            }

            unset($usuario['password_hash']);

            $response->getBody()->write(json_encode(['ok' => true, 'usuario' => $usuario]));

            return $response;
        });

        // PUT /usuarios/{id} — {nombre} (cualquier admin).
        $app->put('/usuarios/{id}', function (Request $request, Response $response, array $args): Response {
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

            $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE id = ?');
            $stmt->execute([$args['id']]);
            if (!$stmt->fetch()) {
                return self::jsonError($response, 404, 'usuario_not_found');
            }

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_actualizar_usuario(?, ?)');
                $stmt->execute([$args['id'], $nombre]);
                $stmt->closeCursor();
            } else {
                $pdo->prepare('UPDATE usuarios SET nombre = ? WHERE id = ?')
                    ->execute([$nombre, $args['id']]);
            }

            Eventos::registrar($pdo, null, 'usuario_editado', [
                'usuario_id' => $args['id'],
                'nombre' => $nombre,
            ]);

            $response->getBody()->write(json_encode(['ok' => true]));

            return $response;
        });

        // DELETE /usuarios/{id} (cualquier admin). Borra sus sesiones tambien.
        $app->delete('/usuarios/{id}', function (Request $request, Response $response, array $args): Response {
            $pdo = Database::connection();

            $admin = Auth::admin($pdo, $request);
            if (!$admin) {
                return self::jsonError($response, 401, 'unauthorized');
            }

            $stmt = $pdo->prepare('SELECT id, nombre, public_key, email FROM usuarios WHERE id = ?');
            $stmt->execute([$args['id']]);
            $usuario = $stmt->fetch();
            if (!$usuario) {
                return self::jsonError($response, 404, 'usuario_not_found');
            }

            $pdo->prepare('DELETE FROM usuario_sessions WHERE usuario_id = ?')
                ->execute([$args['id']]);

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_eliminar_usuario(?)');
                $stmt->execute([$args['id']]);
                $stmt->closeCursor();
            } else {
                $pdo->prepare('DELETE FROM usuarios WHERE id = ?')
                    ->execute([$args['id']]);
            }

            Eventos::registrar($pdo, null, 'usuario_eliminado', [
                'usuario_id' => $usuario['id'],
                'nombre' => $usuario['nombre'],
                'public_key' => $usuario['public_key'],
                'email' => $usuario['email'],
            ]);

            $response->getBody()->write(json_encode(['ok' => true]));

            return $response;
        });

        // POST /auth/usuario/login — {email, password} -> {ok, token, usuario}
        // Abierto (auto-registro + login sin admin).
        $app->post('/auth/usuario/login', function (Request $request, Response $response): Response {
            $body = json_decode((string) $request->getBody(), true) ?? [];
            $email = strtolower(trim((string) ($body['email'] ?? '')));
            $password = (string) ($body['password'] ?? '');

            if ($email === '' || $password === '') {
                return self::jsonError($response, 400, 'missing_fields');
            }

            $pdo = Database::connection();

            $usuario = self::buscarPorEmail($pdo, $email);

            if (!$usuario || $usuario['password_hash'] === null
                || !password_verify($password, $usuario['password_hash'])
            ) {
                return self::jsonError($response, 401, 'credenciales_invalidas');
            }

            $token = bin2hex(random_bytes(24));
            $expira = gmdate('Y-m-d H:i:s', time() + 7 * 86400);

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_crear_sesion_usuario(?, ?, ?)');
                $stmt->execute([$usuario['id'], $token, $expira]);
                $stmt->closeCursor();
            } else {
                $pdo->prepare('INSERT INTO usuario_sessions (usuario_id, token, expira_en) VALUES (?, ?, ?)')
                    ->execute([$usuario['id'], $token, $expira]);
            }

            Eventos::registrar($pdo, null, 'usuario_login', [
                'usuario_id' => $usuario['id'],
                'email' => $usuario['email'],
            ]);

            $response->getBody()->write(json_encode([
                'ok' => true,
                'token' => $token,
                'usuario' => [
                    'id' => $usuario['id'],
                    'nombre' => $usuario['nombre'],
                    'public_key' => $usuario['public_key'],
                    'email' => $usuario['email'],
                ],
            ]));

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

    /**
     * @return array<string, mixed>|false
     */
    private static function buscarPorEmail(PDO $pdo, string $email)
    {
        if (self::isMysql()) {
            $stmt = $pdo->prepare('CALL sp_obtener_usuario_por_email(?)');
            $stmt->execute([$email]);
            $usuario = $stmt->fetch();
            $stmt->closeCursor();

            return $usuario;
        }

        $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE email = ?');
        $stmt->execute([$email]);

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
