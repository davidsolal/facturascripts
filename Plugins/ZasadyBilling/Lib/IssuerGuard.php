<?php

namespace FacturaScripts\Plugins\ZasadyBilling\Lib;

use FacturaScripts\Core\Model\Cliente;
use FacturaScripts\Core\Model\FacturaCliente;
use FacturaScripts\Core\Where;

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
            // Belt and braces for the settings marker above. The marker is
            // written AFTER the invoice is saved and activated, so a failure
            // there leaves a real, non-editable invoice with no marker and the
            // next run would issue a duplicate - which is exactly how four
            // duplicate invoices were produced during acceptance. The invoices
            // themselves are the authority: if one already exists for this
            // company inside the period's month, the period is taken.
            if (self::hasIssuedInvoice($p->idempresa, $isoDate)) {
                $out[] = 'invoice already exists for ' . substr($isoDate, 0, 7);
            }

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

    /**
     * True when a non-draft customer invoice already exists for this company
     * with a date inside the period's month.
     *
     * Only non-draft documents count: an abandoned draft is not an issued
     * invoice (core prints a "still a sketch" banner on it and it was never
     * sent), and refusing on one would strand the period until someone deleted
     * it by hand. The date range is the calendar month of $isoDate, not the
     * exact invoice date, so a run on the 20th still sees an invoice issued on
     * the 19th.
     */
    private static function hasIssuedInvoice(int $idempresa, string $isoDate): bool
    {
        $where = [
            Where::eq('idempresa', $idempresa),
            Where::gte('fecha', Period::monthStart($isoDate)),
            Where::lte('fecha', Period::monthEnd($isoDate)),
        ];

        // limit 0 = no limit (DataBase::selectLimit only appends LIMIT when > 0).
        foreach (FacturaCliente::all($where, ['fecha' => 'ASC'], 0, 0) as $factura) {
            if (false === $factura->editable) {
                return true;
            }
        }

        return false;
    }
}
