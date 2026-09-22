<?php declare(strict_types=1);

namespace App\Routes;

use App\Database;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

final class CajaRoutes
{
    public static function register(App $app): void
    {
        $app->post('/cajas', function (Request $request, Response $response): Response {
            $body = json_decode((string) $request->getBody(), true) ?? [];
            $nombre = trim((string) ($body['nombre'] ?? ''));
            $curso = trim((string) ($body['curso'] ?? ''));
            $publicKey = trim((string) ($body['public_key'] ?? ''));
            $umbral = (int) ($body['umbral'] ?? 0);

            if ($nombre === '' || $publicKey === '' || $umbral < 1) {
                return self::jsonError($response, 400, 'missing_fields');
            }

            $pdo = Database::connection();

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_crear_caja(?, ?, ?, ?, @id)');
                $stmt->execute([$nombre, $curso, $publicKey, $umbral]);
                $stmt->closeCursor();
                $id = $pdo->query('SELECT @id AS id')->fetch()['id'];
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO cajas (nombre, curso, public_key, umbral) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$nombre, $curso, $publicKey, $umbral]);
                $id = $pdo->lastInsertId();
            }

            $response->getBody()->write(json_encode(['ok' => true, 'id' => $id]));

            return $response->withStatus(201);
        });

        $app->get('/cajas/{id}', function (Request $request, Response $response, array $args): Response {
            $pdo = Database::connection();

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_obtener_caja(?)');
                $stmt->execute([$args['id']]);
                $caja = $stmt->fetch();
                $stmt->closeCursor();
            } else {
                $stmt = $pdo->prepare('SELECT * FROM cajas WHERE id = ?');
                $stmt->execute([$args['id']]);
                $caja = $stmt->fetch();
            }

            if (!$caja) {
                return self::jsonError($response, 404, 'caja_not_found');
            }

            if (self::isMysql()) {
                $membersStmt = $pdo->prepare('CALL sp_listar_members(?)');
                $membersStmt->execute([$args['id']]);
                $caja['members'] = $membersStmt->fetchAll();
                $membersStmt->closeCursor();
            } else {
                $membersStmt = $pdo->prepare('SELECT id, nombre, public_key FROM members WHERE caja_id = ?');
                $membersStmt->execute([$args['id']]);
                $caja['members'] = $membersStmt->fetchAll();
            }

            $response->getBody()->write(json_encode(['ok' => true, 'caja' => $caja]));

            return $response;
        });

        $app->post('/cajas/{id}/members', function (Request $request, Response $response, array $args): Response {
            $body = json_decode((string) $request->getBody(), true) ?? [];
            $nombre = trim((string) ($body['nombre'] ?? ''));
            $publicKey = trim((string) ($body['public_key'] ?? ''));

            if ($nombre === '' || $publicKey === '') {
                return self::jsonError($response, 400, 'missing_fields');
            }

            $pdo = Database::connection();

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_agregar_member(?, ?, ?, @id)');
                $stmt->execute([$args['id'], $nombre, $publicKey]);
                $stmt->closeCursor();
                $id = $pdo->query('SELECT @id AS id')->fetch()['id'];
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO members (caja_id, nombre, public_key) VALUES (?, ?, ?)'
                );
                $stmt->execute([$args['id'], $nombre, $publicKey]);
                $id = $pdo->lastInsertId();
            }

            $response->getBody()->write(json_encode(['ok' => true, 'id' => $id]));

            return $response->withStatus(201);
        });

        $app->post('/cajas/{id}/proposals', function (Request $request, Response $response, array $args): Response {
            $body = json_decode((string) $request->getBody(), true) ?? [];
            $destino = trim((string) ($body['destino'] ?? ''));
            $monto = trim((string) ($body['monto'] ?? ''));
            $motivo = trim((string) ($body['motivo'] ?? ''));
            $xdr = trim((string) ($body['xdr'] ?? ''));

            if ($destino === '' || $monto === '' || $xdr === '') {
                return self::jsonError($response, 400, 'missing_fields');
            }

            $pdo = Database::connection();

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_crear_proposal(?, ?, ?, ?, ?, @id)');
                $stmt->execute([$args['id'], $destino, $monto, $motivo, $xdr]);
                $stmt->closeCursor();
                $id = $pdo->query('SELECT @id AS id')->fetch()['id'];
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO proposals (caja_id, destino, monto, motivo, xdr, estado) VALUES (?, ?, ?, ?, ?, 'pendiente')"
                );
                $stmt->execute([$args['id'], $destino, $monto, $motivo, $xdr]);
                $id = $pdo->lastInsertId();
            }

            $response->getBody()->write(json_encode(['ok' => true, 'id' => $id]));

            return $response->withStatus(201);
        });

        $app->get('/cajas/{id}/proposals', function (Request $request, Response $response, array $args): Response {
            $pdo = Database::connection();

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_listar_proposals(?)');
                $stmt->execute([$args['id']]);
                $proposals = $stmt->fetchAll();
                $stmt->closeCursor();
            } else {
                $stmt = $pdo->prepare('SELECT * FROM proposals WHERE caja_id = ? ORDER BY id DESC');
                $stmt->execute([$args['id']]);
                $proposals = $stmt->fetchAll();
            }

            foreach ($proposals as &$proposal) {
                if (self::isMysql()) {
                    $sigStmt = $pdo->prepare('CALL sp_listar_signatures(?)');
                    $sigStmt->execute([$proposal['id']]);
                    $proposal['signatures'] = $sigStmt->fetchAll();
                    $sigStmt->closeCursor();
                } else {
                    $sigStmt = $pdo->prepare(
                        'SELECT member_id, firmado_en FROM proposal_signatures WHERE proposal_id = ?'
                    );
                    $sigStmt->execute([$proposal['id']]);
                    $proposal['signatures'] = $sigStmt->fetchAll();
                }
            }

            $response->getBody()->write(json_encode(['ok' => true, 'proposals' => $proposals]));

            return $response;
        });

        $app->post('/proposals/{id}/signatures', function (Request $request, Response $response, array $args): Response {
            $body = json_decode((string) $request->getBody(), true) ?? [];
            $memberId = (int) ($body['member_id'] ?? 0);
            $xdr = trim((string) ($body['xdr'] ?? ''));

            if ($memberId < 1 || $xdr === '') {
                return self::jsonError($response, 400, 'missing_fields');
            }

            $pdo = Database::connection();

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_firmar_proposal(?, ?, ?)');
                $stmt->execute([$args['id'], $memberId, $xdr]);
                $stmt->closeCursor();
            } else {
                $update = $pdo->prepare('UPDATE proposals SET xdr = ? WHERE id = ?');
                $update->execute([$xdr, $args['id']]);

                $insert = $pdo->prepare(
                    'INSERT INTO proposal_signatures (proposal_id, member_id, firmado_en) VALUES (?, ?, CURRENT_TIMESTAMP)'
                );
                $insert->execute([$args['id'], $memberId]);
            }

            $response->getBody()->write(json_encode(['ok' => true]));

            return $response->withStatus(201);
        });
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
