<?php

namespace FacturaScripts\Plugins\ZasadyBilling\Lib;

use FacturaScripts\Core\Model\Cliente;

/**
 * Pre-flight checks for Issuer::issue(): every reason an invoice must NOT be
 * created, expressed as human-readable blockers rather than exceptions, so
 * a caller (cron job, manual trigger) can show them to an operator instead
 * of crashing.
 */
final class IssuerGuard
{
    /** @return string[] blockers; empty means the invoice may be issued */
    public static function check(Profile $p, ?Rate $rate, string $isoDate): array
    {
        $out = [];
        if (false === $p->enabled) {
            $out[] = 'profile disabled';
        }
        if ($p->amount <= 0) {
            $out[] = 'amount not set';
        }
        if ($p->codcliente === '' || $p->codserie === '') {
            $out[] = 'client or series missing';
        }
        if (null === $rate) {
            $out[] = 'no ECB rate within 5 days of ' . $isoDate;
        }
        if (Period::isIssued($p->idempresa, $isoDate)) {
            $out[] = 'period already issued';
        }

        // Only hit the database when there's actually a client code to look
        // up: profiles with an empty codcliente already failed the check
        // above, and this ordering lets callers (and this class's own unit
        // test) probe a blank/disabled profile without a DB connection.
        if ($p->codcliente !== '') {
            $cliente = new Cliente();
            if (false === $cliente->load($p->codcliente)) {
                $out[] = 'client missing or without billing address';
            } else {
                // Cliente has no direccion property of its own - the postal
                // address lives on its default billing Contacto.
                $billing = $cliente->getDefaultAddress('billing');
                if (empty($billing->direccion)) {
                    $out[] = 'client missing or without billing address';
                }
            }
        }

        return $out;
    }
}
