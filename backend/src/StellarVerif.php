<?php declare(strict_types=1);

namespace App;

use Soneso\StellarSDK\AbstractTransaction;
use Soneso\StellarSDK\CreateAccountOperation;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\Network;
use Soneso\StellarSDK\PaymentOperation;
use Soneso\StellarSDK\Transaction;

// Verificacion server-side de los comprobantes XDR: que la operacion sea
// la declarada (misma cuenta, destino y monto) y que las firmas sean
// criptograficamente validas de las claves conocidas.
final class StellarVerif
{
    /**
     * Parsea el XDR y comprueba que describe exactamente el pago declarado:
     * cuenta de la caja, un solo movimiento, destino y monto correctos.
     * Null si el XDR no es una transaccion valida o no coincide.
     */
    public static function txDelPago(string $xdr, string $cajaPub, string $destino, string $monto): ?AbstractTransaction
    {
        try {
            $tx = AbstractTransaction::fromEnvelopeBase64XdrString($xdr);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$tx instanceof Transaction) {
            return null;
        }

        try {
            $origen = $tx->getSourceAccount()->getAccountId();
            if ($origen !== $cajaPub) {
                return null;
            }

            $ops = $tx->getOperations();
            if (count($ops) !== 1) {
                return null;
            }
            $op = $ops[0];

            if ($op instanceof PaymentOperation) {
                $opDestino = $op->getDestination()->getAccountId();
                $opMonto = $op->getAmount();
            } elseif ($op instanceof CreateAccountOperation) {
                $opDestino = $op->getDestination();
                $opMonto = $op->getStartingBalance();
            } else {
                return null;
            }

            if ($opDestino !== $destino || !self::mismoMonto($opMonto, $monto)) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        return $tx;
    }

    /**
     * Dos transacciones son la misma si su signatureBase coincide:
     * la base incluye red, cuenta, secuencia y operaciones — no las firmas.
     */
    public static function mismoTx(AbstractTransaction $a, AbstractTransaction $b): bool
    {
        return $a->signatureBase(Network::testnet()) === $b->signatureBase(Network::testnet());
    }

    /**
     * Claves conocidas (miembros + maestra) que tienen una firma
     * criptograficamente valida sobre esta transaccion.
     *
     * @param list<string> $pubkeys
     * @return list<string> public keys que firmaron de verdad
     */
    public static function firmantesReales(AbstractTransaction $tx, array $pubkeys): array
    {
        $hash = $tx->hash(Network::testnet());
        $validos = [];

        foreach ($pubkeys as $pub) {
            try {
                $kp = KeyPair::fromAccountId($pub);
            } catch (\Throwable $e) {
                continue;
            }
            $hint = $kp->getHint();
            foreach ($tx->getSignatures() as $firma) {
                if ($firma->getHint() !== $hint) {
                    continue;
                }
                if ($kp->verifySignature($firma->getSignature(), $hash)) {
                    $validos[] = $pub;
                    break;
                }
            }
        }

        return $validos;
    }

    // "1" == "1.0000000" — el XDR guarda 7 decimales fijos.
    private static function mismoMonto(string $xdrMonto, string $declarado): bool
    {
        return abs((float) $xdrMonto - (float) $declarado) < 0.0000001;
    }
}
