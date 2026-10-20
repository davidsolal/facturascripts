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
        // Month-end terms: fechadevengo is the document's due date and the one
        // the receipts are aligned to below.
        $dueDate = Period::dueDate($isoDate);
        $factura->fechadevengo = $dueDate;

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
        // The observation sentence is client-facing and must state what was
        // actually used: the ECB publication date (not the invoice date - they
        // differ whenever the invoice date is a weekend or TARGET holiday) and
        // whether the operator overrode it. It carries nothing machine-readable:
        // core's stock PDFDocument prints this column verbatim, so a payload
        // here reaches client documents on every render path except ours. The
        // same two values are persisted in Settings by rememberRate() below.
        $factura->observaciones = sprintf(
            '%s (1 EUR = %s USD). Out of scope of Spanish VAT under arts. 68-70 LIVA '
            . '(services to a non-EU business).',
            $rate->source === Rate::SOURCE_MANUAL
                ? 'Manual rate'
                : RateToken::sourceLabel($rate->source) . ' of ' . $rate->dateUsed,
            Tools::number($rate->usdPerEur, 4)
        );
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

        // Core creates each receipt (in that same calculate() pass, via
        // ReceiptGenerator) with vencimiento derived from formaspago's
        // plazovencimiento, i.e. "+N days" or "+N months" from the invoice
        // date. That cannot express "month-end": the acceptance run got
        // 19-10-2026 for company 1 (TRANS, +1 month) and 19-09-2026 for
        // company 2 (+0 days) on invoices dated 19-09-2026 - two different,
        // both wrong due dates for the same contractual term. The document's
        // fechadevengo IS the due date, and PDFDocument prints the receipt's
        // vencimiento (never fechadevengo), so the receipts are realigned to
        // it here, before the invoice becomes non-editable.
        $this->alignReceiptsToDueDate($factura, $dueDate);

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

        // Rate provenance, machine-readable, keyed by the company and the code
        // just assigned by the save above (codes are unique per company only,
        // so the company must be part of the key - see RateToken::key()).
        // Written before (and independently of) the period marker: the marker
        // guards against a second invoice, this is the document's own
        // metadata. Failing loudly is deliberate - the invoice exists and is
        // non-editable, and a run that silently loses the
        // rate provenance would print a rate date nobody can prove.
        if (false === $this->rememberRate((int)$factura->idempresa, (string)$factura->codigo, $rate)) {
            throw new \RuntimeException(
                'invoice ' . $factura->codigo . ' was issued but its rate metadata ('
                . $rate->dateUsed . '/' . $rate->source . ') could not be saved in settings '
                . RateToken::GROUP . '; do not re-run blindly'
            );
        }

        if (false === Period::markIssued($p->idempresa, $isoDate, $factura->codigo)) {
            // The invoice exists and is non-editable: it cannot be rolled back,
            // and reporting success here would hide a period that the marker
            // does not protect. Fail loudly. IssuerGuard's existing-invoice
            // check (belt and braces, independent of this marker) is what stops
            // the next run from issuing a duplicate.
            throw new \RuntimeException(
                'invoice ' . $factura->codigo . ' was issued but the period marker '
                . Period::key($p->idempresa, $isoDate) . ' could not be saved; do not re-run blindly'
            );
        }

        return [
            'codigo' => $factura->codigo,
            'totaleuros' => (float)$factura->totaleuros,
            // idasiento can legitimately be 0 when the company has no
            // exercise/diary configured yet - that is not an error here.
            'idasiento' => (int)$factura->idasiento,
        ];
    }

    /**
     * Persists the rate actually used for $codigo in company $idempresa and
     * reports whether the settings table accepted the write.
     *
     * Both parts of the key matter: `codigo` is unique per company only, so
     * storing by code alone let two companies share one entry and would print
     * one company's manual override on the other company's invoice.
     *
     * Tools::settingsSet() only fills an in-memory cache, so its result has to
     * be flushed with settingsSave() - the same trap Profile::save() and
     * Period::markIssued() document. A returned false here means the metadata
     * is gone while the invoice is already immutable, hence the caller's throw.
     */
    private function rememberRate(int $idempresa, string $codigo, Rate $rate): bool
    {
        $key = RateToken::key($idempresa, $codigo);
        if ($key === '') {
            return false;
        }

        $value = RateToken::encode($rate);
        if (null === $value) {
            return false;
        }

        Tools::settingsSet(RateToken::GROUP, $key, $value);
        return Tools::settingsSave();
    }

    /**
     * Sets every receipt of the invoice to the document's own due date.
     *
     * Core cannot express "month-end" in formaspago (only +N days / +N
     * months), so the receipt date core derives is wrong for this contract and
     * visible to the client: PDFDocument::insertInvoiceReceipts() prints
     * `recibo->vencimiento` and nothing prints `fechadevengo`. Aligning the
     * receipts is therefore the only way the printed due date can be the legal
     * one.
     *
     * The amount, numbering and currency are left untouched: this is a date
     * correction on an invoice that has not been sent yet, not a re-issue.
     */
    private function alignReceiptsToDueDate(FacturaCliente $factura, string $dueDate): void
    {
        $target = strtotime($dueDate);
        foreach ($factura->getReceipts() as $receipt) {
            if (strtotime((string)$receipt->vencimiento) === $target) {
                continue;
            }

            $receipt->vencimiento = $dueDate;
            // Receipt::save() calls updateInvoice() -> ReceiptGenerator, which
            // recomputes the receipt set from the invoice total. That is
            // unnecessary here and could regenerate a receipt with the old
            // formaspago date; the generator disables the same hook when it
            // creates receipts itself.
            $receipt->disableInvoiceUpdate(true);
            if (false === $receipt->save()) {
                throw new \RuntimeException(
                    'could not set due date ' . $dueDate . ' on receipt ' . $receipt->numero
                    . ' of invoice ' . $factura->codigo
                );
            }
        }
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
