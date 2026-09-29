<?php declare(strict_types=1);

namespace App\Routes;

use App\Correo;
use App\Database;
use App\Eventos;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

// Cambio de clave por código al correo: POST /auth/recuperar envia un
// codigo de 6 digitos (15 min), POST /auth/cambiar-clave lo valida y
// actualiza la clave del usuario o del admin segun el email.
final class RecuperacionRoutes
{
    public static function register(App $app): void
    {
        // POST /auth/recuperar {email} -> siempre {ok:true}
        // (no revela si el correo existe ni de que tipo es la cuenta).
        $app->post('/auth/recuperar', function (Request $request, Response $response): Response {
            $body = json_decode((string) $request->getBody(), true) ?? [];
            $email = strtolower(trim((string) ($body['email'] ?? '')));

            if ($email === '') {
                return self::jsonError($response, 400, 'missing_fields');
            }

            $pdo = Database::connection();
            $tipo = self::tipoCuenta($pdo, $email);

            if ($tipo !== null) {
                $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $expira = gmdate('Y-m-d H:i:s', time() + 15 * 60);

                if (self::isMysql()) {
                    $stmt = $pdo->prepare('CALL sp_crear_codigo_recuperacion(?, ?, ?, ?)');
                    $stmt->execute([$tipo, $email, $codigo, $expira]);
                    $stmt->closeCursor();
                } else {
                    $pdo->prepare(
                        'INSERT INTO codigos_recuperacion (tipo, email, codigo, expira_en) VALUES (?, ?, ?, ?)'
                    )->execute([$tipo, $email, $codigo, $expira]);
                }

                Correo::codigoRecuperacion($email, $codigo);
            }

            $response->getBody()->write(json_encode(['ok' => true]));

            return $response;
        });

        // POST /auth/cambiar-clave {email, codigo, password}
        // -> {ok:true} | 400 codigo_invalido | 400 missing_fields
        $app->post('/auth/cambiar-clave', function (Request $request, Response $response): Response {
            $body = json_decode((string) $request->getBody(), true) ?? [];
            $email = strtolower(trim((string) ($body['email'] ?? '')));
            $codigo = trim((string) ($body['codigo'] ?? ''));
            $password = (string) ($body['password'] ?? '');

            if ($email === '' || $codigo === '' || $password === '') {
                return self::jsonError($response, 400, 'missing_fields');
            }

            $pdo = Database::connection();

            $ahora = gmdate('Y-m-d H:i:s');
            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_obtener_codigo_recuperacion(?, ?, ?)');
                $stmt->execute([$email, $codigo, $ahora]);
                $fila = $stmt->fetch();
                $stmt->closeCursor();
            } else {
                $stmt = $pdo->prepare(
                    'SELECT * FROM codigos_recuperacion
                     WHERE email = ? AND codigo = ? AND usado = 0 AND expira_en > ?
                     ORDER BY id DESC LIMIT 1'
                );
                $stmt->execute([$email, $codigo, $ahora]);
                $fila = $stmt->fetch();
            }

            if (!$fila) {
                return self::jsonError($response, 400, 'codigo_invalido');
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $tipo = $fila['tipo'];

            if (self::isMysql()) {
                $stmt = $pdo->prepare(
                    $tipo === 'admin'
                        ? 'CALL sp_actualizar_clave_admin(?, ?)'
                        : 'CALL sp_actualizar_clave_usuario(?, ?)'
                );
                $stmt->execute([$email, $hash]);
                $stmt->closeCursor();
            } else {
                $tabla = $tipo === 'admin' ? 'admin_users' : 'usuarios';
                $pdo->prepare("UPDATE {$tabla} SET password_hash = ? WHERE email = ?")
                    ->execute([$hash, $email]);
            }

            // Todas las sesiones de la cuenta quedan cerradas: hay que entrar de nuevo.
            if ($tipo === 'admin') {
                $pdo->prepare(
                    'DELETE FROM admin_sessions WHERE admin_user_id IN
                     (SELECT id FROM admin_users WHERE email = ?)'
                )->execute([$email]);
            } else {
                $pdo->prepare(
                    'DELETE FROM usuario_sessions WHERE usuario_id IN
                     (SELECT id FROM usuarios WHERE email = ?)'
                )->execute([$email]);
            }

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_marcar_codigo_usado(?)');
                $stmt->execute([$fila['id']]);
                $stmt->closeCursor();
            } else {
                $pdo->prepare('UPDATE codigos_recuperacion SET usado = 1 WHERE id = ?')
                    ->execute([$fila['id']]);
            }

            Eventos::registrar($pdo, null, 'clave_cambiada', [
                'tipo' => $tipo,
                'email' => $email,
            ]);

            $response->getBody()->write(json_encode(['ok' => true]));

            return $response;
        });
    }

    // 'usuario' | 'admin' | null segun donde exista el email.
    private static function tipoCuenta(PDO $pdo, string $email): ?string
    {
        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            return 'usuario';
        }

        $stmt = $pdo->prepare('SELECT id FROM admin_users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            return 'admin';
        }

        return null;
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
