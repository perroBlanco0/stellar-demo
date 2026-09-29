<?php declare(strict_types=1);

namespace App;

use PDO;
use Psr\Http\Message\ServerRequestInterface as Request;

// Auth minima: Authorization: Bearer <token> -> admin de una organizacion.
// Las sesiones viven en admin_sessions con expira_en (sin JWT ni librerias).
final class Auth
{
    /**
     * Devuelve {id, email, organizacion_id} del admin autenticado, o null.
     *
     * @return array<string, mixed>|null
     */
    public static function admin(PDO $pdo, Request $request): ?array
    {
        if (!preg_match('/Bearer\s+(\S+)/i', $request->getHeaderLine('Authorization'), $m)) {
            return null;
        }

        if (self::isMysql()) {
            $stmt = $pdo->prepare('CALL sp_obtener_sesion_por_token(?)');
            $stmt->execute([$m[1]]);
            $admin = $stmt->fetch();
            $stmt->closeCursor();

            return $admin ?: null;
        }

        $stmt = $pdo->prepare(
            'SELECT au.id, au.email, au.organizacion_id
             FROM admin_sessions s
             JOIN admin_users au ON au.id = s.admin_user_id
             WHERE s.token = ? AND s.expira_en > CURRENT_TIMESTAMP'
        );
        $stmt->execute([$m[1]]);
        $admin = $stmt->fetch();

        return $admin ?: null;
    }

    /**
     * Devuelve {id, nombre, public_key, email} del usuario autenticado, o null.
     * Mismo patron que admin(): sesiones en usuario_sessions con expira_en.
     *
     * @return array<string, mixed>|null
     */
    public static function usuario(PDO $pdo, Request $request): ?array
    {
        if (!preg_match('/Bearer\s+(\S+)/i', $request->getHeaderLine('Authorization'), $m)) {
            return null;
        }

        if (self::isMysql()) {
            $stmt = $pdo->prepare('CALL sp_obtener_sesion_usuario_por_token(?)');
            $stmt->execute([$m[1]]);
            $usuario = $stmt->fetch();
            $stmt->closeCursor();

            return $usuario ?: null;
        }

        $stmt = $pdo->prepare(
            'SELECT u.id, u.nombre, u.public_key, u.email
             FROM usuario_sessions s
             JOIN usuarios u ON u.id = s.usuario_id
             WHERE s.token = ? AND s.expira_en > CURRENT_TIMESTAMP'
        );
        $stmt->execute([$m[1]]);
        $usuario = $stmt->fetch();

        return $usuario ?: null;
    }

    private static function isMysql(): bool
    {
        return ($_ENV['DB_DRIVER'] ?? 'pgsql') === 'mysql';
    }
}
