<?php declare(strict_types=1);

namespace App\Routes;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\Exceptions\HorizonRequestException;
use Soneso\StellarSDK\Network;
use Soneso\StellarSDK\PaymentOperationBuilder;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\TransactionBuilder;

final class FaucetRoutes
{
    public static function register(App $app): void
    {
        $app->post('/faucet', function (Request $request, Response $response): Response {
            $body = json_decode((string) $request->getBody(), true);
            $destination = is_array($body) ? trim((string) ($body['destination'] ?? '')) : '';

            if ($destination === '') {
                return self::jsonError($response, 400, 'missing_destination');
            }

            try {
                KeyPair::fromAccountId($destination);
            } catch (\Throwable $e) {
                return self::jsonError($response, 400, 'invalid_destination');
            }

            $issuerSecret = $_ENV['CUSD_ISSUER_SECRET'] ?? '';
            $issuerPublic = $_ENV['CUSD_ISSUER_PUBLIC'] ?? '';
            $amount = $_ENV['FAUCET_AMOUNT'] ?? '500';

            if ($issuerSecret === '' || $issuerPublic === '') {
                return self::jsonError($response, 500, 'issuer_not_configured');
            }

            $issuerKeyPair = KeyPair::fromSeed($issuerSecret);
            $cUSD = Asset::createNonNativeAsset('cUSD', $issuerPublic);
            $sdk = StellarSDK::getTestNetInstance();

            try {
                $issuerAccount = $sdk->requestAccount($issuerKeyPair->getAccountId());

                $payment = (new PaymentOperationBuilder($destination, $cUSD, $amount))->build();
                $transaction = (new TransactionBuilder($issuerAccount))
                    ->addOperation($payment)
                    ->build();
                $transaction->sign($issuerKeyPair, Network::testnet());

                $result = $sdk->submitTransaction($transaction);
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
