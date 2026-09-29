<?php declare(strict_types=1);

namespace App\Routes;

use App\Auth;
use App\Database;
use App\Eventos;
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

            // Crear cajas exige login: quedan asociadas a su organizacion.
            $admin = Auth::admin($pdo, $request);
            if (!$admin) {
                return self::jsonError($response, 401, 'unauthorized');
            }
            $organizacionId = (int) $admin['organizacion_id'];

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_crear_caja(?, ?, ?, ?, ?, @id)');
                $stmt->execute([$organizacionId, $nombre, $curso, $publicKey, $umbral]);
                $stmt->closeCursor();
                $id = $pdo->query('SELECT @id AS id')->fetch()['id'];
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO cajas (organizacion_id, nombre, curso, public_key, umbral) VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->execute([$organizacionId, $nombre, $curso, $publicKey, $umbral]);
                $id = $pdo->lastInsertId();
            }

            Eventos::registrar($pdo, (int) $id, 'caja_creada', [
                'organizacion_id' => $organizacionId,
                'nombre' => $nombre,
                'curso' => $curso,
                'public_key' => $publicKey,
                'umbral' => $umbral,
            ]);

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

            Eventos::registrar($pdo, (int) $args['id'], 'miembro_agregado', [
                'member_id' => $id,
                'nombre' => $nombre,
                'public_key' => $publicKey,
            ]);

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

            Eventos::registrar($pdo, (int) $args['id'], 'propuesta_creada', [
                'proposal_id' => $id,
                'destino' => $destino,
                'monto' => $monto,
                'motivo' => $motivo,
            ]);

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

            $stmt = $pdo->prepare('SELECT caja_id FROM proposals WHERE id = ?');
            $stmt->execute([$args['id']]);
            Eventos::registrar($pdo, (int) $stmt->fetchColumn(), 'propuesta_firmada', [
                'proposal_id' => $args['id'],
                'member_id' => $memberId,
            ]);

            $response->getBody()->write(json_encode(['ok' => true]));

            return $response->withStatus(201);
        });

        // PUT /cajas/{id} — {nombre}
        // Solo un admin de la organizacion de la caja (o cualquier admin si
        // la caja no tiene organizacion, legacy).
        $app->put('/cajas/{id}', function (Request $request, Response $response, array $args): Response {
            $body = json_decode((string) $request->getBody(), true) ?? [];
            $nombre = trim((string) ($body['nombre'] ?? ''));

            if ($nombre === '') {
                return self::jsonError($response, 400, 'missing_fields');
            }

            $pdo = Database::connection();

            $caja = self::buscarCaja($pdo, $args['id']);
            if (!$caja) {
                return self::jsonError($response, 404, 'caja_not_found');
            }
            if ($error = self::checkCajaAdmin($pdo, $request, $response, $caja)) {
                return $error;
            }

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_actualizar_caja(?, ?)');
                $stmt->execute([$args['id'], $nombre]);
                $stmt->closeCursor();
            } else {
                $pdo->prepare('UPDATE cajas SET nombre = ? WHERE id = ?')
                    ->execute([$nombre, $args['id']]);
            }

            Eventos::registrar($pdo, (int) $args['id'], 'caja_editada', [
                'nombre' => $nombre,
            ]);

            $response->getBody()->write(json_encode(['ok' => true]));

            return $response;
        });

        // DELETE /cajas/{id} — borra hijos primero (signatures de sus
        // proposals, proposals, members, eventos) y despues la caja.
        $app->delete('/cajas/{id}', function (Request $request, Response $response, array $args): Response {
            $pdo = Database::connection();

            $caja = self::buscarCaja($pdo, $args['id']);
            if (!$caja) {
                return self::jsonError($response, 404, 'caja_not_found');
            }
            if ($error = self::checkCajaAdmin($pdo, $request, $response, $caja)) {
                return $error;
            }

            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    'DELETE FROM proposal_signatures WHERE proposal_id IN
                     (SELECT id FROM proposals WHERE caja_id = ?)'
                )->execute([$args['id']]);
                $pdo->prepare('DELETE FROM proposals WHERE caja_id = ?')->execute([$args['id']]);
                $pdo->prepare('DELETE FROM members WHERE caja_id = ?')->execute([$args['id']]);
                $pdo->prepare('DELETE FROM eventos WHERE caja_id = ?')->execute([$args['id']]);

                if (self::isMysql()) {
                    $stmt = $pdo->prepare('CALL sp_eliminar_caja(?)');
                    $stmt->execute([$args['id']]);
                    $stmt->closeCursor();
                } else {
                    $pdo->prepare('DELETE FROM cajas WHERE id = ?')->execute([$args['id']]);
                }

                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            // Con caja_id null para que no se lo lleve el borrado de la caja.
            Eventos::registrar($pdo, null, 'caja_eliminada', [
                'caja_id' => $args['id'],
                'nombre' => $caja['nombre'],
            ]);

            $response->getBody()->write(json_encode(['ok' => true]));

            return $response;
        });

        // PUT /cajas/{id}/members/{memberId} — {nombre}
        $app->put('/cajas/{id}/members/{memberId}', function (Request $request, Response $response, array $args): Response {
            $body = json_decode((string) $request->getBody(), true) ?? [];
            $nombre = trim((string) ($body['nombre'] ?? ''));

            if ($nombre === '') {
                return self::jsonError($response, 400, 'missing_fields');
            }

            $pdo = Database::connection();

            $caja = self::buscarCaja($pdo, $args['id']);
            if (!$caja) {
                return self::jsonError($response, 404, 'caja_not_found');
            }
            if ($error = self::checkCajaAdmin($pdo, $request, $response, $caja)) {
                return $error;
            }

            $stmt = $pdo->prepare('SELECT id FROM members WHERE id = ? AND caja_id = ?');
            $stmt->execute([$args['memberId'], $args['id']]);
            if (!$stmt->fetch()) {
                return self::jsonError($response, 404, 'member_not_found');
            }

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_actualizar_miembro(?, ?)');
                $stmt->execute([$args['memberId'], $nombre]);
                $stmt->closeCursor();
            } else {
                $pdo->prepare('UPDATE members SET nombre = ? WHERE id = ?')
                    ->execute([$nombre, $args['memberId']]);
            }

            Eventos::registrar($pdo, (int) $args['id'], 'miembro_editado', [
                'member_id' => $args['memberId'],
                'nombre' => $nombre,
            ]);

            $response->getBody()->write(json_encode(['ok' => true]));

            return $response;
        });

        // DELETE /cajas/{id}/members/{memberId}
        $app->delete('/cajas/{id}/members/{memberId}', function (Request $request, Response $response, array $args): Response {
            $pdo = Database::connection();

            $caja = self::buscarCaja($pdo, $args['id']);
            if (!$caja) {
                return self::jsonError($response, 404, 'caja_not_found');
            }
            if ($error = self::checkCajaAdmin($pdo, $request, $response, $caja)) {
                return $error;
            }

            $stmt = $pdo->prepare('SELECT id FROM members WHERE id = ? AND caja_id = ?');
            $stmt->execute([$args['memberId'], $args['id']]);
            if (!$stmt->fetch()) {
                return self::jsonError($response, 404, 'member_not_found');
            }

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_eliminar_miembro(?)');
                $stmt->execute([$args['memberId']]);
                $stmt->closeCursor();
            } else {
                $pdo->prepare('DELETE FROM members WHERE id = ?')
                    ->execute([$args['memberId']]);
            }

            Eventos::registrar($pdo, (int) $args['id'], 'miembro_eliminado', [
                'member_id' => $args['memberId'],
            ]);

            $response->getBody()->write(json_encode(['ok' => true]));

            return $response;
        });
    }

    /**
     * @return array<string, mixed>|false
     */
    private static function buscarCaja(PDO $pdo, string $id)
    {
        if (self::isMysql()) {
            $stmt = $pdo->prepare('CALL sp_obtener_caja(?)');
            $stmt->execute([$id]);
            $caja = $stmt->fetch();
            $stmt->closeCursor();

            return $caja;
        }

        $stmt = $pdo->prepare('SELECT * FROM cajas WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch();
    }

    // Mutaciones de caja: admin de la organizacion de la caja, o cualquier
    // admin si la caja no tiene organizacion_id (legacy). Null = autorizado.
    /**
     * @param array<string, mixed> $caja
     */
    private static function checkCajaAdmin(PDO $pdo, Request $request, Response $response, array $caja): ?Response
    {
        $admin = Auth::admin($pdo, $request);
        if (!$admin) {
            return self::jsonError($response, 401, 'unauthorized');
        }
        if ($caja['organizacion_id'] !== null
            && (int) $caja['organizacion_id'] !== (int) $admin['organizacion_id']
        ) {
            return self::jsonError($response, 403, 'forbidden');
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
