<?php declare(strict_types=1);

namespace App\Routes;

use App\Acceso;
use App\Auth;
use App\Database;
use App\Eventos;
use App\StellarVerif;
use Soneso\StellarSDK\AbstractTransaction;
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
                $membersStmt = $pdo->prepare('SELECT id, nombre, public_key, puede_aprobar, usuario_id FROM members WHERE caja_id = ?');
                $membersStmt->execute([$args['id']]);
                $caja['members'] = $membersStmt->fetchAll();
            }

            $response->getBody()->write(json_encode(['ok' => true, 'caja' => $caja]));

            return $response;
        });

        // POST /cajas/{id}/members — dos modos:
        // {usuario_id}: vincula un usuario registrado (usa su nombre y
        // public_key, guarda el vinculo; 409 si su clave ya es miembro).
        // {nombre, public_key}: claves sueltas, flujo original sin cambios.
        // Solo el admin de la organizacion administra miembros.
        $app->post('/cajas/{id}/members', function (Request $request, Response $response, array $args): Response {
            $body = json_decode((string) $request->getBody(), true) ?? [];
            $usuarioId = (int) ($body['usuario_id'] ?? 0);
            $nombre = trim((string) ($body['nombre'] ?? ''));
            $publicKey = trim((string) ($body['public_key'] ?? ''));

            $pdo = Database::connection();

            $caja = self::buscarCaja($pdo, $args['id']);
            if (!$caja) {
                return self::jsonError($response, 404, 'caja_not_found');
            }
            if ($error = Acceso::checkCajaAdmin($pdo, $request, $response, $caja)) {
                return $error;
            }

            if ($usuarioId > 0) {
                $usuario = self::buscarUsuario($pdo, $usuarioId);
                if (!$usuario) {
                    return self::jsonError($response, 404, 'usuario_not_found');
                }
                $nombre = $usuario['nombre'];
                $publicKey = $usuario['public_key'];

                if (self::miembroConClave($pdo, (int) $args['id'], $publicKey)) {
                    return self::jsonError($response, 409, 'miembro_duplicado');
                }
            } elseif ($nombre === '' || $publicKey === '') {
                return self::jsonError($response, 400, 'missing_fields');
            }

            $usuarioParam = $usuarioId > 0 ? $usuarioId : null;

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_agregar_member(?, ?, ?, ?, @id)');
                $stmt->execute([$args['id'], $nombre, $publicKey, $usuarioParam]);
                $stmt->closeCursor();
                $id = $pdo->query('SELECT @id AS id')->fetch()['id'];
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO members (caja_id, nombre, public_key, usuario_id) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$args['id'], $nombre, $publicKey, $usuarioParam]);
                $id = $pdo->lastInsertId();
            }

            Eventos::registrar($pdo, (int) $args['id'], 'miembro_agregado', [
                'member_id' => $id,
                'nombre' => $nombre,
                'public_key' => $publicKey,
                'usuario_id' => $usuarioParam,
            ]);

            $response->getBody()->write(json_encode(['ok' => true, 'id' => $id, 'puede_aprobar' => true]));

            return $response->withStatus(201);
        });

        // POST /cajas/{id}/proposals — participantes de la caja (admin o
        // usuario-miembro). El XDR se verifica: debe ser el pago declarado.
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

            $caja = self::buscarCaja($pdo, $args['id']);
            if (!$caja) {
                return self::jsonError($response, 404, 'caja_not_found');
            }
            if ($error = Acceso::checkCajaParticipante($pdo, $request, $response, $caja)) {
                return $error;
            }
            if (!StellarVerif::txDelPago($xdr, $caja['public_key'], $destino, $monto)) {
                return self::jsonError($response, 422, 'xdr_invalido');
            }

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

        // POST /proposals/{id}/signatures — la firma se verifica de verdad:
        // el XDR debe ser la misma transaccion guardada y contener una firma
        // criptograficamente valida de la clave de ese miembro. Ya no se
        // acepta un XDR cualquiera ni se pisan firmas previas.
        $app->post('/proposals/{id}/signatures', function (Request $request, Response $response, array $args): Response {
            $body = json_decode((string) $request->getBody(), true) ?? [];
            $memberId = (int) ($body['member_id'] ?? 0);
            $xdr = trim((string) ($body['xdr'] ?? ''));

            if ($memberId < 1 || $xdr === '') {
                return self::jsonError($response, 400, 'missing_fields');
            }

            $pdo = Database::connection();

            $stmt = $pdo->prepare('SELECT caja_id, xdr, estado FROM proposals WHERE id = ?');
            $stmt->execute([$args['id']]);
            $proposal = $stmt->fetch();
            if (!$proposal) {
                return self::jsonError($response, 404, 'proposal_not_found');
            }
            if ($proposal['estado'] !== 'pendiente') {
                return self::jsonError($response, 409, 'proposal_already_executed');
            }

            // Solo firmas de miembros de la caja, y solo si su aprobacion
            // sigue encendida (las firmas ya emitidas siguen contando).
            $miembro = self::buscarMiembro($pdo, $memberId);
            if (!$miembro || (int) $miembro['caja_id'] !== (int) $proposal['caja_id']) {
                return self::jsonError($response, 404, 'member_not_found');
            }
            if (!self::aprobacionActiva($miembro)) {
                return self::jsonError($response, 403, 'aprobacion_desactivada');
            }

            // Un miembro aprueba una sola vez.
            $dup = $pdo->prepare('SELECT 1 FROM proposal_signatures WHERE proposal_id = ? AND member_id = ?');
            $dup->execute([$args['id'], $memberId]);
            if ($dup->fetchColumn()) {
                return self::jsonError($response, 409, 'ya_firmo');
            }

            // El XDR entrante debe ser LA MISMA transaccion guardada y traer
            // una firma real de este miembro, sin perder las anteriores.
            try {
                $txGuardada = AbstractTransaction::fromEnvelopeBase64XdrString($proposal['xdr']);
                $txNueva = AbstractTransaction::fromEnvelopeBase64XdrString($xdr);
            } catch (\Throwable $e) {
                return self::jsonError($response, 422, 'xdr_invalido');
            }
            if (!StellarVerif::mismoTx($txGuardada, $txNueva)) {
                return self::jsonError($response, 422, 'xdr_invalido');
            }

            $pubkeys = array_merge(
                [(string) $miembro['public_key']],
                self::pubkeysFirmantesPrevios($pdo, (int) $args['id'])
            );
            $firmantes = StellarVerif::firmantesReales($txNueva, $pubkeys);
            if (!in_array((string) $miembro['public_key'], $firmantes, true)) {
                return self::jsonError($response, 403, 'firma_invalida');
            }

            $perdidas = array_diff($pubkeys, $firmantes);
            if ($perdidas) {
                return self::jsonError($response, 409, 'firma_pisada');
            }

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

            Eventos::registrar($pdo, (int) $proposal['caja_id'], 'propuesta_firmada', [
                'proposal_id' => $args['id'],
                'member_id' => $memberId,
            ]);

            $response->getBody()->write(json_encode(['ok' => true]));

            return $response->withStatus(201);
        });

        // DELETE /proposals/{id} — retira una solicitud pendiente
        // (las ejecutadas son historial y no se borran). Solo admin.
        $app->delete('/proposals/{id}', function (Request $request, Response $response, array $args): Response {
            $pdo = Database::connection();

            $stmt = $pdo->prepare('SELECT caja_id, estado FROM proposals WHERE id = ?');
            $stmt->execute([$args['id']]);
            $proposal = $stmt->fetch();
            if (!$proposal) {
                return self::jsonError($response, 404, 'proposal_not_found');
            }

            $caja = self::buscarCaja($pdo, (string) $proposal['caja_id']);
            if (!$caja) {
                return self::jsonError($response, 404, 'caja_not_found');
            }
            if ($error = Acceso::checkCajaAdmin($pdo, $request, $response, $caja)) {
                return $error;
            }
            if ($proposal['estado'] !== 'pendiente') {
                return self::jsonError($response, 409, 'proposal_already_executed');
            }

            $pdo->prepare('DELETE FROM proposal_signatures WHERE proposal_id = ?')->execute([$args['id']]);
            $pdo->prepare('DELETE FROM proposals WHERE id = ?')->execute([$args['id']]);

            Eventos::registrar($pdo, (int) $proposal['caja_id'], 'propuesta_eliminada', [
                'proposal_id' => $args['id'],
            ]);

            $response->getBody()->write(json_encode(['ok' => true]));

            return $response;
        });

        // DELETE /proposals/{id}/signatures/{memberId} — quita la firma mas
        // reciente de ese miembro en la solicitud. Solo admin.
        $app->delete('/proposals/{id}/signatures/{memberId}', function (Request $request, Response $response, array $args): Response {
            $pdo = Database::connection();

            $stmt = $pdo->prepare('SELECT caja_id, estado FROM proposals WHERE id = ?');
            $stmt->execute([$args['id']]);
            $proposal = $stmt->fetch();
            if (!$proposal) {
                return self::jsonError($response, 404, 'proposal_not_found');
            }

            $caja = self::buscarCaja($pdo, (string) $proposal['caja_id']);
            if (!$caja) {
                return self::jsonError($response, 404, 'caja_not_found');
            }
            if ($error = Acceso::checkCajaAdmin($pdo, $request, $response, $caja)) {
                return $error;
            }

            $ultima = $pdo->prepare(
                'SELECT id FROM proposal_signatures WHERE proposal_id = ? AND member_id = ?
                 ORDER BY firmado_en DESC, id DESC LIMIT 1'
            );
            $ultima->execute([$args['id'], $args['memberId']]);
            $firmaId = $ultima->fetchColumn();
            if (!$firmaId) {
                return self::jsonError($response, 404, 'signature_not_found');
            }
            $pdo->prepare('DELETE FROM proposal_signatures WHERE id = ?')->execute([$firmaId]);

            $response->getBody()->write(json_encode(['ok' => true]));

            return $response;
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
            if ($error = Acceso::checkCajaAdmin($pdo, $request, $response, $caja)) {
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
            if ($error = Acceso::checkCajaAdmin($pdo, $request, $response, $caja)) {
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

        // PUT /cajas/{id}/members/{memberId} — {nombre} y/o {puede_aprobar}.
        // puede_aprobar en false apaga solo NUEVAS firmas del miembro;
        // las firmas ya emitidas siguen contando para el umbral.
        $app->put('/cajas/{id}/members/{memberId}', function (Request $request, Response $response, array $args): Response {
            $body = json_decode((string) $request->getBody(), true) ?? [];
            $nombre = trim((string) ($body['nombre'] ?? ''));
            $cambiarNombre = $nombre !== '';
            $cambiarAprobacion = array_key_exists('puede_aprobar', $body);
            $puedeAprobar = (bool) ($body['puede_aprobar'] ?? false);

            if (!$cambiarNombre && !$cambiarAprobacion) {
                return self::jsonError($response, 400, 'missing_fields');
            }

            $pdo = Database::connection();

            $caja = self::buscarCaja($pdo, $args['id']);
            if (!$caja) {
                return self::jsonError($response, 404, 'caja_not_found');
            }
            if ($error = Acceso::checkCajaAdmin($pdo, $request, $response, $caja)) {
                return $error;
            }

            $miembro = self::buscarMiembro($pdo, (int) $args['memberId']);
            if (!$miembro || (int) $miembro['caja_id'] !== (int) $args['id']) {
                return self::jsonError($response, 404, 'member_not_found');
            }

            $nuevoNombre = $cambiarNombre ? $nombre : $miembro['nombre'];
            $nuevaAprobacion = $cambiarAprobacion
                ? $puedeAprobar
                : self::aprobacionActiva($miembro);

            if (self::isMysql()) {
                $stmt = $pdo->prepare('CALL sp_actualizar_miembro(?, ?, ?)');
                $stmt->execute([$args['memberId'], $nuevoNombre, $nuevaAprobacion ? 1 : 0]);
                $stmt->closeCursor();
            } else {
                $pdo->prepare('UPDATE members SET nombre = ?, puede_aprobar = ? WHERE id = ?')
                    ->execute([$nuevoNombre, $nuevaAprobacion ? 1 : 0, $args['memberId']]);
            }

            if ($cambiarNombre) {
                Eventos::registrar($pdo, (int) $args['id'], 'miembro_editado', [
                    'member_id' => $args['memberId'],
                    'nombre' => $nombre,
                ]);
            }
            if ($cambiarAprobacion) {
                Eventos::registrar($pdo, (int) $args['id'], $puedeAprobar ? 'miembro_aprobacion_on' : 'miembro_aprobacion_off', [
                    'member_id' => $args['memberId'],
                    'puede_aprobar' => $puedeAprobar,
                ]);
            }

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
            if ($error = Acceso::checkCajaAdmin($pdo, $request, $response, $caja)) {
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
        if (!is_numeric($id)) {
            return false;
        }
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

    /**
     * @return array<string, mixed>|false
     */
    private static function buscarMiembro(PDO $pdo, int $id)
    {
        if (self::isMysql()) {
            $stmt = $pdo->prepare('CALL sp_obtener_miembro(?)');
            $stmt->execute([$id]);
            $miembro = $stmt->fetch();
            $stmt->closeCursor();

            return $miembro;
        }

        $stmt = $pdo->prepare('SELECT * FROM members WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch();
    }

    /**
     * @return array<string, mixed>|false
     */
    private static function buscarUsuario(PDO $pdo, int $id)
    {
        if (self::isMysql()) {
            $stmt = $pdo->prepare('CALL sp_obtener_usuario_por_id(?)');
            $stmt->execute([$id]);
            $usuario = $stmt->fetch();
            $stmt->closeCursor();

            return $usuario;
        }

        $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch();
    }

    private static function miembroConClave(PDO $pdo, int $cajaId, string $publicKey): bool
    {
        if (self::isMysql()) {
            $stmt = $pdo->prepare('CALL sp_listar_members(?)');
            $stmt->execute([$cajaId]);
            $members = $stmt->fetchAll();
            $stmt->closeCursor();
            foreach ($members as $m) {
                if ($m['public_key'] === $publicKey) {
                    return true;
                }
            }

            return false;
        }

        $stmt = $pdo->prepare('SELECT id FROM members WHERE caja_id = ? AND public_key = ?');
        $stmt->execute([$cajaId, $publicKey]);

        return (bool) $stmt->fetch();
    }

    // Normaliza puede_aprobar: sqlite/mysql devuelven 1/0, pg devuelve
    // bool o 't'/'f' segun el driver.
    /**
     * @param array<string, mixed> $miembro
     */
    private static function aprobacionActiva(array $miembro): bool
    {
        $v = $miembro['puede_aprobar'] ?? true;

        return $v === true || $v === 1 || $v === '1' || $v === 't' || $v === 'true';
    }

    // Public keys de los miembros que ya firmaron la propuesta (para
    // comprobar que una nueva firma no pisa las anteriores).
    /**
     * @return list<string>
     */
    private static function pubkeysFirmantesPrevios(PDO $pdo, int $proposalId): array
    {
        $stmt = $pdo->prepare(
            'SELECT m.public_key FROM proposal_signatures s
             JOIN members m ON m.id = s.member_id WHERE s.proposal_id = ?'
        );
        $stmt->execute([$proposalId]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
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
