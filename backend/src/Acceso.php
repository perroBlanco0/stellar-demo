<?php declare(strict_types=1);

namespace App;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Quien puede tocar una caja: el super-admin, un admin de su organizacion,
// o un usuario registrado que es miembro de esa caja.
final class Acceso
{
    /**
     * Admin autorizado sobre la caja: super (cualquiera), admin de la org
     * de la caja, o cualquier admin si la caja es legacy (sin org).
     *
     * @param array<string, mixed> $caja
     */
    public static function checkCajaAdmin(PDO $pdo, Request $request, Response $response, array $caja): ?Response
    {
        $admin = Auth::admin($pdo, $request);
        if (!$admin) {
            return self::jsonError($response, 401, 'unauthorized');
        }
        if (($admin['rol'] ?? 'admin') !== 'super'
            && $caja['organizacion_id'] !== null
            && (int) $caja['organizacion_id'] !== (int) $admin['organizacion_id']
        ) {
            return self::jsonError($response, 403, 'forbidden');
        }

        return null;
    }

    /**
     * Para acciones de miembro (proponer, ejecutar): pasa si es admin
     * autorizado, o un usuario registrado vinculado a un miembro de la caja.
     *
     * @param array<string, mixed> $caja
     */
    public static function checkCajaParticipante(PDO $pdo, Request $request, Response $response, array $caja): ?Response
    {
        $admin = Auth::admin($pdo, $request);
        if ($admin
            && (($admin['rol'] ?? 'admin') === 'super'
                || $caja['organizacion_id'] === null
                || (int) $caja['organizacion_id'] === (int) $admin['organizacion_id'])
        ) {
            return null;
        }

        $usuario = Auth::usuario($pdo, $request);
        if ($usuario && self::usuarioEsMiembro($pdo, (int) $caja['id'], (int) $usuario['id'])) {
            return null;
        }

        return self::jsonError($response, 401, 'unauthorized');
    }

    public static function usuarioEsMiembro(PDO $pdo, int $cajaId, int $usuarioId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM members WHERE caja_id = ? AND usuario_id = ? LIMIT 1');
        $stmt->execute([$cajaId, $usuarioId]);

        return (bool) $stmt->fetchColumn();
    }

    private static function jsonError(Response $response, int $status, string $error): Response
    {
        $response->getBody()->write(json_encode(['ok' => false, 'error' => $error]));

        return $response->withStatus($status);
    }
}
