<?php declare(strict_types=1);

namespace App\Routes;

use App\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Soneso\StellarSDK\AbstractTransaction;
use Soneso\StellarSDK\Exceptions\HorizonRequestException;
use Soneso\StellarSDK\StellarSDK;

final class ExecuteRoutes
{
    public static function register(App $app): void
    {
        $app->post('/proposals/{id}/ejecutar', function (Request $request, Response $response, array $args): Response {
            $pdo = Database::connection();

            $stmt = $pdo->prepare('SELECT * FROM proposals WHERE id = ?');
            $stmt->execute([$args['id']]);
            $proposal = $stmt->fetch();

            if (!$proposal) {
                return self::jsonError($response, 404, 'proposal_not_found');
            }

            if ($proposal['estado'] === 'ejecutada') {
                return self::jsonError($response, 409, 'proposal_already_executed');
            }

            $cajaStmt = $pdo->prepare('SELECT umbral FROM cajas WHERE id = ?');
            $cajaStmt->execute([$proposal['caja_id']]);
            $caja = $cajaStmt->fetch();

            if (!$caja) {
                return self::jsonError($response, 404, 'caja_not_found');
            }

            $sigStmt = $pdo->prepare('SELECT COUNT(*) FROM proposal_signatures WHERE proposal_id = ?');
            $sigStmt->execute([$args['id']]);
            $firmas = (int) $sigStmt->fetchColumn();

            if ($firmas < (int) $caja['umbral']) {
                $response->getBody()->write(json_encode([
                    'ok' => false,
                    'error' => 'not_enough_signatures',
                    'firmas' => $firmas,
                    'umbral' => (int) $caja['umbral'],
                ]));

                return $response->withStatus(409);
            }

            $sdk = StellarSDK::getTestNetInstance();

            try {
                $tx = AbstractTransaction::fromEnvelopeBase64XdrString($proposal['xdr']);
                $result = $sdk->submitTransaction($tx);

                $update = $pdo->prepare("UPDATE proposals SET estado = 'ejecutada' WHERE id = ?");
                $update->execute([$args['id']]);

                $response->getBody()->write(json_encode(['ok' => true, 'hash' => $result->getHash()]));

                return $response;
            } catch (HorizonRequestException $e) {
                $extras = $e->getHorizonErrorResponse()?->getExtras();
                $response->getBody()->write(json_encode([
                    'ok' => false,
                    'error' => 'horizon_rejected',
                    'result_codes' => [
                        'transaction' => $extras?->getResultCodesTransaction(),
                        'operations' => $extras?->getResultCodesOperation(),
                    ],
                ]));

                return $response->withStatus(422);
            }
        });
    }

    private static function jsonError(Response $response, int $status, string $error): Response
    {
        $response->getBody()->write(json_encode(['ok' => false, 'error' => $error]));

        return $response->withStatus($status);
    }
}
