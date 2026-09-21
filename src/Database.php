<?php declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $dsn = self::buildDsn();

        self::$connection = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        return self::$connection;
    }

    private static function buildDsn(): string
    {
        // Render inyecta DATABASE_URL con formato postgres://user:pass@host:port/db
        $url = $_ENV['DATABASE_URL'] ?? '';
        if ($url !== '') {
            $parts = parse_url($url);
            $host = $parts['host'] ?? 'localhost';
            $port = $parts['port'] ?? 5432;
            $db = ltrim($parts['path'] ?? '', '/');
            $user = $parts['user'] ?? '';
            $pass = $parts['pass'] ?? '';

            return sprintf(
                'pgsql:host=%s;port=%s;dbname=%s;user=%s;password=%s',
                $host,
                $port,
                $db,
                $user,
                $pass
            );
        }

        // Fallback a variables sueltas (desarrollo local)
        $host = $_ENV['PGHOST'] ?? 'localhost';
        $port = $_ENV['PGPORT'] ?? '5432';
        $db = $_ENV['PGDATABASE'] ?? 'caja';
        $user = $_ENV['PGUSER'] ?? 'postgres';
        $pass = $_ENV['PGPASSWORD'] ?? '';

        return sprintf('pgsql:host=%s;port=%s;dbname=%s;user=%s;password=%s', $host, $port, $db, $user, $pass);
    }
}
