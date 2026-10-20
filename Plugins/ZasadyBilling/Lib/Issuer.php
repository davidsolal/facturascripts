<?php

namespace FacturaScripts\Plugins\ZasadyBilling\Lib;

use FacturaScripts\Core\Lib\Calculator;
use FacturaScripts\Core\Lib\TaxExceptions;
use FacturaScripts\Core\Model\Almacen;
use FacturaScripts\Core\Model\FacturaCliente;
use FacturaScripts\Core\Model\FormaPago;
use FacturaScripts\Core\Model\Impuesto;
use FacturaScripts\Core\Model\Serie;
use FacturaScripts\Core\Tools;

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

        // Copy name/NIF/billing address onto the document. Core's
        // BusinessDocument::test() rejects a row with a null cifnif or
        // nombrecliente, and it does not fill them itself on save: the UI and
        // ApiCreateDocument both go through setSubject()/updateSubject() when
        // the subject is chosen. Skipping it made every save fail with three
        // unrelated-looking "field-can-not-be-null" warnings.
        if (false === $factura->updateSubject()) {
            throw new \RuntimeException('client ' . $p->codcliente . ' could not be loaded');
        }
        $factura->codserie = $p->codserie;
        // Warehouse must be resolved from the company being invoiced, not
        // from core's global default. BusinessDocument::clear() seeds
        // codalmacen from Tools::settings('default', 'codalmacen'), and
        // setDate() feeds it straight into setWarehouse(string $codalmacen):
        // when that setting is unset (seed data that never went through the
        // wizard, API-created companies) PHP 8 throws a TypeError on null.
        // Copying the global default verbatim is not a fix either: it belongs
        // to exactly one company, and setWarehouse() overwrites idempresa with
        // the warehouse's company, so company 2's invoice would be silently
        // booked under company 1.
        $factura->codalmacen = $this->warehouseFor((int)$p->idempresa);
        $factura->coddivisa = 'USD';
        $factura->tasaconv = $rate->usdPerEur;
        $factura->fecha = $isoDate;
        $factura->fechadevengo = Period::dueDate($isoDate);

        // Payment method, resolved against the issuing company: core refuses a
        // null codpago, and the client record cannot carry a single valid code
        // for a two-company setup because formaspago is per-company (company 1
        // has CONT/TRANS/PAYPAL/TARJETA, company 2 only has '1'). Copying the
        // client's code verbatim would therefore leave one of the two invoices
        // pointing at a payment method that does not exist for its issuer.
        $factura->codpago = $this->paymentMethodFor((int)$p->idempresa, (string)$factura->codpago);

        // Deliberately leave operacion unset. Core's
        // TaxExceptions::isValidCombination() only pairs
        // InvoiceOperation::EXPORT ('exportacion') with
        // ES_TAX_EXCEPTION_21 (goods exports), and Cliente::test()
        // enforces that pairing on the client record. Our case is
        // services supplied by a Spanish autonomo to a US business with
        // place of supply outside Spain - the correct classification is
        // arts. 68-70 LIVA (ES_TAX_EXCEPTION_68_70 below on the line),
        // which core only allows when operacion is EMPTY. Setting
        // 'exportacion' here would mismatch the line's exception and make
        // the client record unsavable.
        $factura->observaciones = 'ECB rate ' . $rate->dateUsed . ' (1 EUR = ' . $rate->usdPerEur
            . ' USD). Out of scope of Spanish VAT under arts. 68-70 LIVA (services to a non-EU business).';
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

        // Core creates every invoice in the company's default status, which for
        // FacturaCliente is the draft ("Brouillon"/"Boceto"). A draft is not an
        // invoice: PDFExport prints a red "this invoice is still a sketch" banner
        // over it. Apply the same transition the UI performs - the first active,
        // non-editable status for the document type - which also flips
        // `editable` through TransformerDocument::save().
        foreach ($factura->getAvailableStatus() as $status) {
            if ($status->editable || !$status->activo) {
                continue;
            }

            $factura->idestado = $status->idestado;
            if (false === $factura->save()) {
                throw new \RuntimeException('could not set invoice status for company ' . $p->idempresa);
            }
            break;
        }

        // Re-read from the DB: Calculator::calculate() mutates $factura in
        // place, but reloading guarantees totaleuros/idasiento reflect what
        // was actually persisted (including anything a save hook changed).
        if (false === $factura->load($factura->idfactura)) {
            throw new \RuntimeException('invoice reload failed for company ' . $p->idempresa . ' after save');
        }

        Period::markIssued($p->idempresa, $isoDate, $factura->codigo);

        return [
            'codigo' => $factura->codigo,
            'totaleuros' => (float)$factura->totaleuros,
            // idasiento can legitimately be 0 when the company has no
            // exercise/diary configured yet - that is not an error here.
            'idasiento' => (int)$factura->idasiento,
        ];
    }

    /**
     * Returns the warehouse code this company's invoices should be booked on.
     *
     * Prefers the instance-wide default warehouse when (and only when) it
     * belongs to the same company, otherwise the lowest codalmacen of that
     * company - deterministic, and never another company's warehouse, because
     * setWarehouse() derives idempresa from whichever warehouse it is given.
     */
    private function warehouseFor(int $idempresa): string
    {
        $almacenes = Almacen::allWhereEq('idempresa', $idempresa, ['codalmacen' => 'ASC']);
        if ($almacenes === []) {
            // Fail loudly: core would otherwise die later with an unrelated
            // TypeError deep inside setDate(), which reads as a code fault
            // rather than the configuration gap it is.
            throw new \RuntimeException('no warehouse configured for company ' . $idempresa);
        }

        $default = (string)Tools::settings('default', 'codalmacen');
        foreach ($almacenes as $almacen) {
            if ((string)$almacen->codalmacen === $default) {
                return (string)$almacen->codalmacen;
            }
        }

        return (string)$almacenes[0]->codalmacen;
    }

    /**
     * Returns a payment method code that exists for this company.
     *
     * Preference order: the code already on the document (copied from the
     * client by updateSubject()), then the instance-wide default, then the
     * company's lowest codpago. A candidate is only accepted when formaspago
     * actually has that code for this company, so an invoice can never
     * reference another company's payment method.
     */
    private function paymentMethodFor(int $idempresa, string $fromClient): string
    {
        $codes = [];
        foreach (FormaPago::allWhereEq('idempresa', $idempresa, ['codpago' => 'ASC']) as $method) {
            $codes[] = (string)$method->codpago;
        }

        foreach ([$fromClient, (string)Tools::settings('default', 'codpago')] as $candidate) {
            if ($candidate !== '' && in_array($candidate, $codes, true)) {
                return $candidate;
            }
        }

        if ($codes === []) {
            throw new \RuntimeException('no payment method configured for company ' . $idempresa);
        }

        return $codes[0];
    }
}
