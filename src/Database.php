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

        ['dsn' => $dsn, 'user' => $user, 'pass' => $pass] = self::buildConfig();

        self::$connection = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        if (str_starts_with($dsn, 'sqlite:')) {
            self::$connection->exec('PRAGMA foreign_keys = ON');
        }

        return self::$connection;
    }

    /**
     * @return array{dsn: string, user: ?string, pass: ?string}
     */
    private static function buildConfig(): array
    {
        $driver = $_ENV['DB_DRIVER'] ?? 'pgsql';

        // DB_DRIVER=sqlite: archivo local, sin servidor — util para
        // desbloquear al frontend mientras el deploy real no esta listo.
        if ($driver === 'sqlite') {
            $path = $_ENV['DB_SQLITE_PATH'] ?? __DIR__ . '/../data/caja.sqlite';

            return ['dsn' => 'sqlite:' . $path, 'user' => null, 'pass' => null];
        }

        // DB_DRIVER=mysql: MySQL/MariaDB local (ej. MySQL Workbench / XAMPP).
        if ($driver === 'mysql') {
            $host = $_ENV['DB_HOST'] ?? 'localhost';
            $port = $_ENV['DB_PORT'] ?? '3306';
            $db = $_ENV['DB_NAME'] ?? 'caja';
            $user = $_ENV['DB_USER'] ?? 'root';
            $pass = $_ENV['DB_PASSWORD'] ?? '';

            return [
                'dsn' => sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $db),
                'user' => $user,
                'pass' => $pass,
            ];
        }

        // Default: Postgres. Render inyecta DATABASE_URL con formato
        // postgres://user:pass@host:port/db al conectar la DB al servicio.
        $url = $_ENV['DATABASE_URL'] ?? '';
        if ($url !== '') {
            $parts = parse_url($url);

            return [
                'dsn' => sprintf(
                    'pgsql:host=%s;port=%s;dbname=%s',
                    $parts['host'] ?? 'localhost',
                    $parts['port'] ?? 5432,
                    ltrim($parts['path'] ?? '', '/')
                ),
                'user' => $parts['user'] ?? null,
                'pass' => $parts['pass'] ?? null,
            ];
        }

        // Fallback a variables sueltas (desarrollo local con Postgres).
        $host = $_ENV['PGHOST'] ?? 'localhost';
        $port = $_ENV['PGPORT'] ?? '5432';
        $db = $_ENV['PGDATABASE'] ?? 'caja';

        return [
            'dsn' => sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $db),
            'user' => $_ENV['PGUSER'] ?? 'postgres',
            'pass' => $_ENV['PGPASSWORD'] ?? '',
        ];
    }
}
