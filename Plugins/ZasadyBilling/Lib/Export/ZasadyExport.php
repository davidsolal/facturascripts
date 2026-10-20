<?php

namespace FacturaScripts\Plugins\ZasadyBilling\Lib\Export;

use FacturaScripts\Core\Lib\Export\PDFExport;
use FacturaScripts\Core\Lib\TaxExceptions;
use FacturaScripts\Core\Model\CuentaBanco;
use FacturaScripts\Core\Model\Empresa;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\ZasadyBilling\Lib\RateToken;

/**
 * Bilingual (EN/ES) invoice export. Renders the stock FacturaScripts PDF and
 * adds, in this order: a rate line under the header stating the rate, the date
 * it was published and its source; a bilingual VAT-exception statement; the
 * issuing company's bank details; the payment-terms line with the real due
 * date; and the legal footer ("<name> - autonomo - <email>").
 *
 * It does NOT recompute any figure: every number is read verbatim from the
 * model (tasaconv, totaleuros, fechadevengo). totaleuros is set once by
 * BusinessDocument::total/tasaconv at save time, and reprinting with a
 * different rounding would make the PDF disagree with what accounting booked.
 *
 * Extends PDFExport, not PDFDocument directly: PDFDocument
 * (Core/Lib/PDF/PDFDocument.php) is declared abstract and never implements
 * ExportBase's addBusinessDocPage/getDoc/newDoc/show/etc — only PDFExport
 * does. PDFExport::addBusinessDocPage() calls the insert*BusinessDoc* hooks on
 * the (non-final) instance, so overriding them here still takes effect via
 * normal PHP dispatch while we inherit a concrete, instantiable export class.
 *
 * Deliberately not final: the Dinamic shim written by dev-stack/install.sh
 * (FacturaScripts\Dinamic\Lib\Export\ZasadyExport) must extend this class —
 * that is how ExportManager::getExportClassName() finds it — so `final` would
 * break class resolution with a fatal error.
 */
class ZasadyExport extends PDFExport
{
    /**
     * Language of the rendered client document, pinned regardless of the
     * instance's FS_LANG or the langcode passed by the caller.
     *
     * The instance UI runs in French; without pinning, every core label
     * ("Facture", "Virement bancaire", "Exceptions fiscales") leaked into a
     * document sent to a US client. English is the client's language and the
     * Spanish legal wording is printed explicitly next to it (VAT statement,
     * rate, terms, bank labels), so the document is EN + ES while the core
     * chrome stays English. This is the export-path enforcement of spec §7's
     * "never print a French invoice to a US client".
     */
    public const DOC_LANG = 'en_EN';

    /**
     * How many of our own blocks have already been written on the current
     * render, used to add the separating blank line once instead of before
     * every block. Reset per document in newDoc().
     */
    private int $blocksPrinted = 0;

    /**
     * Forces the document language instead of accepting the caller's.
     *
     * newDoc() is the only place PDFCore's Translator is told which language to
     * use (PDFExport::newDoc() -> $this->i18n->setLang()), so overriding it here
     * is what makes DOC_LANG actually apply to header, table, totals, receipts
     * and footer translations at once. The $langcode argument is kept for
     * signature compatibility with ExportBase and deliberately ignored.
     */
    public function newDoc(string $title, int $idformat, string $langcode)
    {
        // PDFCore points Cezpdf's tempPath at MyFiles/Cache and Cpdf then writes
        // its font metrics there; nothing creates that folder on a CLI run (only
        // IPFilter does, on web requests), and core's PluginsDeploy wipes it. A
        // missing folder made the export die with a TypeError inside Cpdf before
        // the first line of the invoice was drawn.
        //
        // 0777 and an unconditional chmod because the two callers are different
        // users: the CLI runs as root in this stack while Apache runs as uid
        // 1000, so whichever one creates the folder first would otherwise lock
        // the other out of the font cache. Core's IPFilter creates the same
        // folder 0777 for exactly this reason.
        $cacheDir = FS_FOLDER . '/MyFiles/Cache';
        if (false === is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }
        @chmod($cacheDir, 0777);

        $this->blocksPrinted = 0;
        parent::newDoc($title, $idformat, self::DOC_LANG);
    }

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

        // The date printed must be the date of the rate actually used, not the
        // invoice date: the ECB publishes only on TARGET business days, so an
        // invoice dated Saturday 19-09-2026 uses Friday's rate. Printing
        // $model->fecha claimed the rate was from the 19th while the
        // observations on the same page said the 18th.
        //
        // The metadata lives in Settings keyed by the invoice code (see
        // Lib\RateToken - never in observaciones, which core's stock PDF prints
        // verbatim). An invoice issued before it was stored has no entry: the
        // rate date then falls back to the invoice date, exactly as this line
        // printed before the metadata existed, and the source is omitted
        // because claiming one would be a guess.
        $meta = $this->rateMeta($model);
        $rateLine = null === $meta
            ? $this->dateLabel($model->fecha)
            : RateToken::sourceLabel($meta['source']) . ', ' . $this->dateLabel($meta['date']);

        $this->pdf->ezText(
            sprintf(
                'Exchange rate / Tipo de cambio (%s): 1 EUR = %s %s     Total EUR / Total en EUR: %s EUR',
                $rateLine,
                Tools::number((float)$model->tasaconv, 4),
                $model->coddivisa,
                Tools::number((float)$model->totaleuros, 2)
            ),
            self::FONT_SIZE
        );
    }

    /**
     * Rate metadata stored for this invoice, or null when there is none.
     *
     * Read through Tools::settings() (the getter) with an empty default: the
     * key's absence is the documented "older invoice" case, and decode()
     * rejects anything malformed, so a hand-edited or truncated value degrades
     * to the invoice-date fallback instead of breaking the render.
     *
     * @param object $model
     * @return array{date: string, source: string}|null
     */
    private function rateMeta($model): ?array
    {
        $codigo = RateToken::key((string)($model->codigo ?? ''));
        if ($codigo === '') {
            return null;
        }

        $stored = Tools::settings(RateToken::GROUP, $codigo, '');

        return RateToken::decode(is_string($stored) ? $stored : null);
    }

    /**
     * Suppresses core's VAT-exception block so it can be printed in both
     * languages instead of only the UI's.
     *
     * Core (PDFDocument::insertBusinessDocFooter) prints the heading plus one
     * line per exception code, translated into the current language only — on a
     * French instance that was French, on this export it would be English
     * alone, while the client must be able to read the Spanish legal wording
     * (§4.4). insertBilingualTaxExceptions() renders the same core translation
     * keys (Core/Translation/en_EN.json + es_ES.json) for both languages, so no
     * legal text is invented here. This hook is the only consumer of
     * getTaxExceptions() in core (verified by grep), so returning an empty set
     * removes exactly that block and nothing else.
     *
     * @param object $model
     */
    protected function getTaxExceptions($model): array
    {
        return [];
    }

    /**
     * Appends our blocks after everything the core footer prints (tax
     * breakdown, totals, receipts, format text).
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
        // Core prints `observaciones` verbatim. Nothing writes the
        // machine-readable rate token there any more (it lives in Settings, see
        // Lib\RateToken), but invoices issued before that change still carry one
        // in the database: strip it here so this export stays clean even on an
        // unmigrated instance, then restore the model exactly as it was - a PDF
        // render must never mutate the document it is rendering.
        $rawObservaciones = $model->observaciones ?? null;
        $model->observaciones = RateToken::strip($rawObservaciones);
        try {
            parent::insertBusinessDocFooter($model);
        } finally {
            $model->observaciones = $rawObservaciones;
        }

        $this->insertBilingualTaxExceptions($model);
        $this->insertBankDetails((int)$model->idempresa);
        $this->insertPaymentTerms($model);
        $this->insertLegalFooter((int)$model->idempresa);
    }

    /**
     * VAT statement in English and Spanish.
     *
     * The wording comes from the core translations of the line's
     * `excepcioniva` code (arts. 68-70 LIVA for services to a non-EU business),
     * fetched with Translator::customTrans() so the same legal text the rest of
     * the application uses is printed in both languages.
     *
     * @param object $model
     */
    private function insertBilingualTaxExceptions($model): void
    {
        $codes = [];
        foreach ($model->getLines() as $line) {
            if (!empty($line->excepcioniva)) {
                $codes[(string)$line->excepcioniva] = true;
            }
        }
        if ($codes === []) {
            return;
        }

        $all = TaxExceptions::all();
        $lines = ['VAT / IVA'];
        foreach (array_keys($codes) as $code) {
            $key = $all[$code] ?? null;
            $en = null === $key ? $code : $this->i18n->customTrans('en_EN', $key);
            $es = null === $key ? $code : $this->i18n->customTrans('es_ES', $key);
            $lines[] = 'EN: ' . Tools::fixHtml($this->printableDashes($en));
            $lines[] = 'ES: ' . Tools::fixHtml($this->printableDashes($es));
        }

        $this->insertBlock($lines);
    }

    /**
     * Replaces typographic dashes with ASCII ones for printing.
     *
     * The core translations spell "Arts. 68–70" with en dashes. Cezpdf's WinAnsi
     * font handling does not round-trip them (the extracted text reads
     * "Arts. 6870"), which on a legal paragraph reads as a missing article
     * range. A plain hyphen keeps the meaning and prints reliably.
     */
    private function printableDashes(?string $text): string
    {
        return str_replace(["\u{2013}", "\u{2014}"], '-', (string)$text);
    }

    /**
     * Bank details of the issuing company, or nothing when it has no account.
     *
     * No idempresa means we cannot know which company issued the document, and
     * guessing (default setting) could print another company's account on a
     * legal document, so it is skipped.
     */
    private function insertBankDetails(int $idempresa): void
    {
        $account = $this->getCompanyBankAccount($idempresa);
        if (null === $account) {
            return;
        }

        // Labels are only built for fields that actually carry a value: a
        // payment block showing "BIC:" with nothing behind it is worse than no
        // block at all, and the heading itself is emitted only together with at
        // least the IBAN (getCompanyBankAccount() guarantees it).
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

        $this->insertBlock($lines);
    }

    /**
     * Payment-terms line built from fechadevengo.
     *
     * The terms are "month-end", which formaspago cannot express, and nothing
     * in the stock template prints fechadevengo at all — the visible due date
     * used to be the receipt's formaspago-derived vencimiento (19-10-2026 for
     * one company, 19-09-2026 for the other, on the same invoice date). Issuer
     * now aligns the receipts to fechadevengo; printing it here as well makes
     * the term explicit instead of leaving the client to infer it.
     *
     * @param object $model
     */
    private function insertPaymentTerms($model): void
    {
        if (empty($model->fechadevengo)) {
            return;
        }

        $this->insertBlock([
            'Payment terms / Condiciones de pago: month-end / fin de mes - due '
            . $this->dateLabel($model->fechadevengo),
        ]);
    }

    /**
     * Legal footer: "<company name> - autonomo - <email>" of the issuing
     * company, read from the empresa record.
     */
    private function insertLegalFooter(int $idempresa): void
    {
        if ($idempresa <= 0) {
            return;
        }

        $empresa = new Empresa();
        if (false === $empresa->load($idempresa) || empty($empresa->nombre)) {
            return;
        }

        $line = Tools::fixHtml($empresa->nombre) . ' - autónomo';
        if (!empty($empresa->email)) {
            $line .= ' - ' . Tools::fixHtml($empresa->email);
        }

        $this->insertBlock([$line]);
    }

    /**
     * Prints a block of lines, moving it to a fresh page when it would not fit.
     *
     * ezText() page-breaks per line, so a block started near the bottom margin
     * would leave its heading alone on one page and its content on the next.
     * (FONT_SIZE + 3 over-estimates Cezpdf's line height on purpose, so the
     * check errs towards breaking early.)
     *
     * @param string[] $lines
     */
    private function insertBlock(array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $blockHeight = count($lines) * (self::FONT_SIZE + 3);
        // Only the first appended block gets a separating blank line: each one
        // costs a full line of an already tight single-invoice page, and three
        // of them were enough to push the bank/terms/footer onto a second page
        // (spec §4.4 asks for one page per single-line invoice).
        $leadingBlank = $this->blocksPrinted > 0 ? false : true;
        if ($this->pdf->y - $blockHeight < $this->pdf->ez['bottomMargin']) {
            $this->pdf->ezNewPage();
            // At the top of a fresh page the separating blank line is noise.
            $leadingBlank = false;
        }

        $this->blocksPrinted++;
        $this->pdf->ezText(($leadingBlank ? "\n" : '') . $lines[0], self::FONT_SIZE);
        foreach (array_slice($lines, 1) as $line) {
            $this->pdf->ezText($line, self::FONT_SIZE);
        }
    }

    /**
     * Formats a date for printing. Core models hand date fields back in the
     * instance's DATE_STYLE while values we build ourselves (the rate token)
     * are ISO, so accept both instead of guessing one format.
     */
    private function dateLabel(?string $date): string
    {
        $value = (string)$date;
        if ($value === '') {
            return '';
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? Tools::date($value) : $value;
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
