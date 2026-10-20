<?php

namespace FacturaScripts\Plugins\ZasadyBilling\Lib\Export;

use FacturaScripts\Core\Lib\Export\PDFExport;
use FacturaScripts\Core\Model\CuentaBanco;
use FacturaScripts\Core\Tools;

/**
 * Bilingual (EN/ES) invoice export. Renders the stock FacturaScripts PDF
 * unchanged (header/body/footer all come from the core class) and adds two
 * things of its own: a single extra line under the header with the stored
 * exchange rate and the EUR equivalent of the document total, and the issuing
 * company's bank details at the end of the document (see
 * insertBusinessDocFooter()).
 *
 * Both figures are read verbatim from the model (tasaconv, totaleuros) rather
 * than recomputed here: totaleuros is set once by
 * BusinessDocument::total/tasaconv at save time (see
 * Core/Model/Base/BusinessDocument.php), and reprinting with a different
 * rounding would make the PDF disagree with what accounting already booked.
 *
 * Extends PDFExport, not PDFDocument directly: PDFDocument
 * (Core/Lib/PDF/PDFDocument.php) is declared abstract and never implements
 * ExportBase's addBusinessDocPage/getDoc/newDoc/show/etc — only PDFExport
 * does. PDFExport::addBusinessDocPage() calls $this->insertBusinessDocHeader()
 * on the (non-final) instance, so overriding that hook here still takes
 * effect via normal PHP dispatch while we inherit a concrete, instantiable
 * export class.
 *
 * Deliberately not final: the Dinamic shim written by dev-stack/install.sh
 * (FacturaScripts\Dinamic\Lib\Export\ZasadyExport) must extend this class —
 * that is how ExportManager::getExportClassName() finds it — so `final`
 * would break class resolution with a fatal error.
 */
class ZasadyExport extends PDFExport
{
    /**
     * Inserts the core header untouched, then our EUR/rate addendum.
     *
     * Declared public even though the parent method is protected: PHP allows
     * widening visibility on override, and nothing outside the PDF export
     * flow calls this directly, so the wider visibility has no effect on
     * behaviour.
     *
     * @param object $model
     */
    public function insertBusinessDocHeader($model)
    {
        parent::insertBusinessDocHeader($model);

        // A document already in EUR has tasaconv = 1 and no conversion to
        // show; skip the line rather than print a no-op "1 EUR = 1 EUR".
        if (empty($model->tasaconv) || $model->coddivisa === 'EUR') {
            return;
        }

        // Literal bilingual text for this line: no core i18n key for an
        // exchange rate exists (checked en_EN.json / es_ES.json), and the
        // plugin ships no Translation/ directory, so there is nothing to
        // trans() against. 'date', 'total' etc. elsewhere in the PDF already
        // come from core's own trans() calls.
        //
        // Both figures go through Tools::number() so the separators follow the
        // instance's decimal_separator/thousands_separator settings instead of
        // being hardcoded — the rest of the PDF formats the same way, and a
        // mixed-format invoice would read as two different numbers.
        $this->pdf->ezText(
            sprintf(
                'Exchange rate / Tipo de cambio (%s): 1 EUR = %s %s     Total EUR / Total en EUR: %s EUR',
                $model->fecha,
                Tools::number((float)$model->tasaconv, 4),
                $model->coddivisa,
                Tools::number((float)$model->totaleuros, 2)
            ),
            self::FONT_SIZE
        );
    }

    /**
     * Appends the issuing company's bank details after everything the core
     * footer prints (tax breakdown, totals, receipts, format text).
     *
     * Placed here rather than in insertBusinessDocBody() because the body is
     * followed by the totals block, which the core itself may shift down to
     * INVOICE_TOTALS_Y or push onto a new page: anything drawn from the body
     * would end up on top of the totals. The footer method is the last thing
     * addBusinessDocPage() runs, so a block appended to it is always below the
     * totals and never overlaps them.
     *
     * Declared public for the same reason as insertBusinessDocHeader(): the
     * parent method is protected and PHP allows widening it on override.
     *
     * @param object $model
     */
    public function insertBusinessDocFooter($model)
    {
        parent::insertBusinessDocFooter($model);

        // No idempresa means we cannot know which company issued the
        // document, and guessing (default setting) could print another
        // company's account on a legal document. Skip instead.
        $account = $this->getCompanyBankAccount((int)$model->idempresa);
        if (null === $account) {
            return;
        }

        // Labels are only built for fields that actually carry a value: a
        // payment block showing "BIC:" with nothing behind it is worse than no
        // block at all, and the heading itself is emitted only together with
        // at least the IBAN (getCompanyBankAccount() guarantees it).
        $lines = ['Bank details / Datos bancarios'];
        $lines[] = 'IBAN: ' . $account->getIban(true);
        if (!empty($account->swift)) {
            $lines[] = 'BIC: ' . $account->swift;
        }
        if (!empty($account->descripcion)) {
            // fixHtml() because ezText() parses markup (<b>, <c:alink:...>):
            // an account holder with an accidental '<' must print literally.
            $lines[] = 'Holder / Titular: ' . Tools::fixHtml($account->descripcion);
        }

        // ezText() page-breaks per line, so a block started near the bottom
        // margin would leave its heading alone on one page and the account
        // numbers on the next. Move the whole block to a fresh page when it
        // does not fit (FONT_SIZE + 3 over-estimates Cezpdf's line height on
        // purpose, so the check errs towards breaking early).
        $blockHeight = count($lines) * (self::FONT_SIZE + 3);
        if ($this->pdf->y - $blockHeight < $this->pdf->ez['bottomMargin']) {
            $this->pdf->ezNewPage();
        }

        $this->pdf->ezText("\n" . $lines[0], self::FONT_SIZE);
        foreach (array_slice($lines, 1) as $line) {
            $this->pdf->ezText($line, self::FONT_SIZE);
        }
    }

    /**
     * Returns the company's bank account to print, or null when there is none.
     *
     * cuentasbanco holds one row per company account, keyed only by codcuenta,
     * and has no holder column (the holder is stored in descripcion). Ordering
     * by codcuenta and taking the first row that carries an IBAN keeps the
     * choice reproducible if a company ever has several rows: the lowest
     * codcuenta wins, and rows without an IBAN are not payment data.
     */
    private function getCompanyBankAccount(int $idempresa): ?CuentaBanco
    {
        if ($idempresa <= 0) {
            return null;
        }

        foreach (CuentaBanco::allWhereEq('idempresa', $idempresa, ['codcuenta' => 'ASC']) as $account) {
            if (!empty($account->iban)) {
                return $account;
            }
        }

        return null;
    }
}
