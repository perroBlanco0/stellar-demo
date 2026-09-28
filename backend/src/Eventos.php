<?php declare(strict_types=1);

namespace App;

use PDO;

// Trazabilidad: registra cada accion relevante en la tabla eventos.
final class Eventos
{
    /**
     * @param array<string, mixed> $detalle
     */
    public static function registrar(PDO $pdo, ?int $cajaId, string $tipo, array $detalle): void
    {
        $json = json_encode($detalle, JSON_UNESCAPED_UNICODE);

        if (self::isMysql()) {
            $pdo->prepare('CALL sp_registrar_evento(?, ?, ?)')->execute([$cajaId, $tipo, $json]);
            return;
        }

        $pdo->prepare('INSERT INTO eventos (caja_id, tipo, detalle) VALUES (?, ?, ?)')
            ->execute([$cajaId, $tipo, $json]);
    }

    private static function isMysql(): bool
    {
        return ($_ENV['DB_DRIVER'] ?? 'pgsql') === 'mysql';
    }
}
