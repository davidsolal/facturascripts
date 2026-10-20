<?php

namespace FacturaScripts\Plugins\ZasadyBilling\Lib;

use FacturaScripts\Core\Lib\Calculator;
use FacturaScripts\Core\Lib\InvoiceOperation;
use FacturaScripts\Core\Lib\TaxExceptions;
use FacturaScripts\Core\Model\FacturaCliente;
use FacturaScripts\Core\Model\Impuesto;
use FacturaScripts\Core\Model\Serie;

/**
 * Builds and saves the monthly USD retainer invoice for one company.
 *
 * Callers must run IssuerGuard::check() first and only call issue() when it
 * returns no blockers - this class trusts its inputs and only guards against
 * the lower-level failures a guard pass can't anticipate (missing series,
 * missing tax config, core validation rejecting the save).
 */
final class Issuer
{
    /**
     * @return array{codigo: string, totaleuros: float, idasiento: int}
     */
    public function issue(Profile $p, Rate $rate, string $isoDate): array
    {
        $serie = new Serie();
        if (false === $serie->load($p->codserie)) {
            throw new \RuntimeException('series ' . $p->codserie . ' not found');
        }

        // Task 7 seeds the 0% "out of scope" tax used for the export VAT
        // exception; without it Calculator::apply() would resolve a
        // dangling codimpuesto and the line would fail to save.
        $impuesto = new Impuesto();
        if (false === $impuesto->load('IVA0')) {
            throw new \RuntimeException('IVA0 tax not configured');
        }

        // Numbering: core picks the SecuenciaDocumento for (company, series,
        // exercise) and applies its patron/numero on save. The operator sets
        // those in the Secuencia screen; nothing here invents a number.
        $factura = new FacturaCliente();
        $factura->idempresa = $p->idempresa;
        $factura->codcliente = $p->codcliente;
        $factura->codserie = $p->codserie;
        $factura->coddivisa = 'USD';
        $factura->tasaconv = $rate->usdPerEur;
        $factura->fecha = $isoDate;
        $factura->fechadevengo = Period::dueDate($isoDate);
        $factura->operacion = InvoiceOperation::EXPORT;
        $factura->observaciones = 'ECB rate ' . $rate->dateUsed . ' (1 EUR = ' . $rate->usdPerEur . ' USD).';
        if (false === $factura->save()) {
            throw new \RuntimeException('invoice save failed for company ' . $p->idempresa);
        }

        // getNewLine() needs idfactura, hence the header save above. It also
        // copies excepcioniva from the client record (getSubject()), but we
        // pass it explicitly too - belt and braces per the brief - in case
        // the client isn't configured for export sales yet.
        $linea = $factura->getNewLine([
            'descripcion' => $p->description !== '' ? $p->description : 'Partnership Zasady - Monthly Retainer',
            'cantidad' => 1,
            'pvpunitario' => $p->amount,
            'codimpuesto' => 'IVA0',
            'iva' => 0,
            'excepcioniva' => TaxExceptions::ES_TAX_EXCEPTION_68_70,
        ]);

        // Calculator::calculate(..., true) is the real core entry point for
        // "add lines to a document": it recomputes neto/total/totaliva/etc.
        // from the lines (BusinessDocument::save() alone does NOT do this -
        // only Calculator does, see Core/Controller/ApiCreateDocument.php),
        // then saves the lines and finally the header. That last header
        // save is what InvoiceTrait::onChange() sees as a 'total' change and
        // routes into onChangeTotal(), which creates the accounting entry
        // and receipts automatically - we never call InvoiceToAccounting
        // ourselves.
        $lines = [$linea];
        if (false === Calculator::calculate($factura, $lines, true)) {
            throw new \RuntimeException('invoice totals/save failed for company ' . $p->idempresa);
        }

        // Re-read from the DB: Calculator::calculate() mutates $factura in
        // place, but reloading guarantees totaleuros/idasiento reflect what
        // was actually persisted (including anything a save hook changed).
        $factura->load($factura->idfactura);

        Period::markIssued($p->idempresa, $isoDate, $factura->codigo);

        return [
            'codigo' => $factura->codigo,
            'totaleuros' => (float)$factura->totaleuros,
            // idasiento can legitimately be 0 when the company has no
            // exercise/diary configured yet - that is not an error here.
            'idasiento' => (int)$factura->idasiento,
        ];
    }
}
