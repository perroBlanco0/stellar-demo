<?php declare(strict_types=1);

namespace App\Routes;

use App\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Soneso\StellarSDK\Exceptions\HorizonRequestException;
use Soneso\StellarSDK\StellarSDK;

final class StellarRoutes
{
    public static function register(App $app): void
    {
        $app->get('/cajas/{id}/estado', function (Request $request, Response $response, array $args): Response {
            $stmt = Database::connection()->prepare('SELECT public_key FROM cajas WHERE id = ?');
            $stmt->execute([$args['id']]);
            $caja = $stmt->fetch();

            if (!$caja) {
                $response->getBody()->write(json_encode(['ok' => false, 'error' => 'caja_not_found']));

                return $response->withStatus(404);
            }

            $publicKey = $caja['public_key'];
            $sdk = StellarSDK::getTestNetInstance();

            try {
                $account = $sdk->requestAccount($publicKey);

                $balances = [];
                foreach ($account->getBalances()->toArray() as $b) {
                    $balances[] = [
                        'asset' => $b->getAssetType() === 'native' ? 'XLM' : $b->getAssetCode(),
                        'balance' => $b->getBalance(),
                    ];
                }

                $transacciones = [];
                $payments = $sdk->payments()
                    ->forAccount($publicKey)
                    ->order('desc')
                    ->limit(10)
                    ->execute();

                foreach ($payments->getOperations()->toArray() as $op) {
                    $transacciones[] = [
                        'tipo' => $op->getHumanReadableOperationType(),
                        'creado_en' => $op->getCreatedAt(),
                        'transaction_hash' => $op->getTransactionHash(),
                    ];
                }

                $response->getBody()->write(json_encode([
                    'ok' => true,
                    'public_key' => $publicKey,
                    'balances' => $balances,
                    'transacciones' => $transacciones,
                ]));

                return $response;
            } catch (HorizonRequestException $e) {
                // La cuenta puede no existir aun en Stellar (recien creada, sin fondear).
                $response->getBody()->write(json_encode([
                    'ok' => false,
                    'error' => 'account_not_found_on_stellar',
                    'public_key' => $publicKey,
                ]));

                return $response->withStatus(404);
            }
        });
    }
}
