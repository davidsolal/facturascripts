<?php

namespace FacturaScripts\Plugins\ZasadyBilling\Lib\Export;

use DateTimeImmutable;
use FacturaScripts\Core\Lib\Export\PDFExport;
use FacturaScripts\Core\Lib\TaxExceptions;
use FacturaScripts\Core\Model\CuentaBanco;
use FacturaScripts\Core\Model\Empresa;
use FacturaScripts\Core\Model\Pais;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\ZasadyBilling\Lib\Rate;
use FacturaScripts\Plugins\ZasadyBilling\Lib\RateToken;

/**
 * Bilingual client invoice: the client's language + Spanish.
 *
 * The language pair belongs to the CLIENT, not to the export: it is read from
 * the Language field of the customer record (clientes.langcode). A client in
 * French prints French + Spanish; everything else - including a client with no
 * language set, as the Zasady record has - prints English + Spanish. Spanish is
 * always the second language because the issuers are Spanish autonomos and the
 * Spanish wording is the legally relevant one.
 *
 * The page is drawn from scratch (header band, meta strip, party cards, a line
 * table with the document currency and its EUR equivalent stacked in each price
 * cell, a totals card, payment panel, footer) instead of decorating core's PDF,
 * whose layout is single-currency and takes its labels from the instance UI.
 *
 * No figure accounting booked is recomputed: totals are read from the model
 * (neto, totaliva, total, totaleuros, tasaconv). Only per-line EUR display
 * values are derived, with the document's own rate.
 *
 * Extends PDFExport so ExportManager / the Dinamic shim written by
 * dev-stack/install.sh still resolve it; deliberately not final for that shim.
 */
class ZasadyExport extends PDFExport
{
    /**
     * Language pinned for core's own chrome. Nothing client-facing uses it any
     * more (labels come from LABELS), but newDoc() must not inherit the
     * instance's French UI.
     */
    public const DOC_LANG = 'en_EN';

    /** Primary language used when the client has none. */
    public const DEFAULT_LANG = 'en_EN';

    /** [en, fr, es] */
    private const LABELS = [
        'invoice' => ['Invoice', 'Facture', 'Factura'],
        'date' => ['Invoice date', 'Date de facture', 'Fecha de factura'],
        'due' => ['Due date', 'Échéance', 'Vencimiento'],
        'rate' => ['Exchange rate', 'Taux de change', 'Tipo de cambio'],
        'from' => ['From', 'Émetteur', 'Emisor'],
        'billto' => ['Bill to', 'Facturé à', 'Cliente'],
        'description' => ['Description', 'Description', 'Descripción'],
        'qty' => ['Qty', 'Qté', 'Cant.'],
        'unit' => ['Unit price', 'Prix unitaire', 'Precio unitario'],
        'amount' => ['Amount', 'Montant', 'Importe'],
        'subtotal' => ['Subtotal', 'Sous-total', 'Subtotal'],
        'vat' => ['VAT', 'TVA', 'IVA'],
        'total' => ['Total', 'Total', 'Total'],
        'na' => ['Not applicable', 'Non applicable', 'No aplicable'],
        'method' => ['Payment method', 'Mode de paiement', 'Forma de pago'],
        'transfer' => ['Bank transfer', 'Virement bancaire', 'Transferencia bancaria'],
        'terms' => ['Payment terms', 'Conditions de paiement', 'Condiciones de pago'],
        'monthend' => ['Month-end', 'Fin de mois', 'Fin de mes'],
        'holder' => ['Account holder', 'Titulaire du compte', 'Titular'],
        'notes' => ['Notes', 'Notes', 'Notas'],
        'vatnote' => ['VAT treatment', 'Régime de TVA', 'Tratamiento del IVA'],
        'autonomo' => ['Self-employed', 'Indépendant', 'Autónomo'],
        'bank' => ['Bank details', 'Coordonnées bancaires', 'Datos bancarios'],
    ];

    private const MONTHS = [
        'en_EN' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        'fr_FR' => ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
    ];

    private const MARGIN = 40;

    // palette (0-1 RGB)
    private const INK = [0.09, 0.10, 0.16];
    private const MUTED = [0.30, 0.32, 0.40];
    private const FAINT = [0.45, 0.47, 0.55];
    private const LINE = [0.89, 0.90, 0.93];
    private const CARD = [0.965, 0.970, 0.985];
    private const NAVY = [0.06, 0.08, 0.19];
    private const ACCENT = [0.31, 0.33, 0.93];
    private const ACCENT_SOFT = [0.72, 0.75, 1.0];
    private const WHITE = [1.0, 1.0, 1.0];

    /** Readable title embedded in the PDF (set in addBusinessDocPage()). */
    private string $documentTitle = '';

    /** Primary language of the document being drawn. */
    private string $lang = self::DEFAULT_LANG;

    /** Current drawing cursor, PDF coordinates (origin bottom-left). */
    private float $cy = 0.0;

    // ------------------------------------------------------------------
    // Language
    // ------------------------------------------------------------------

    /**
     * Primary language for a client language code: French for any fr_*,
     * English for everything else (empty, en_*, or a language with no label
     * set yet). Spanish is implicit as the second language.
     */
    public static function primaryLanguage(?string $langcode): string
    {
        $code = strtolower(trim((string)$langcode));
        return strncmp($code, 'fr', 2) === 0 ? 'fr_FR' : self::DEFAULT_LANG;
    }

    /** "EN/ES" or "FR/ES", for logs and docs. */
    public static function pairLabel(?string $langcode): string
    {
        return strtoupper(substr(self::primaryLanguage($langcode), 0, 2)) . '/ES';
    }

    /** @return string[] [document language, Spanish] */
    private function labels(string $key): array
    {
        $row = self::LABELS[$key];
        return [$row[$this->lang === 'fr_FR' ? 1 : 0], $row[2]];
    }

    // ------------------------------------------------------------------
    // Export plumbing
    // ------------------------------------------------------------------

    public function newDoc(string $title, int $idformat, string $langcode)
    {
        // PDFCore points Cezpdf's tempPath at MyFiles/Cache and Cpdf writes its
        // font metrics there; nothing creates that folder on a CLI run and
        // core's PluginsDeploy wipes it. 0777 + unconditional chmod because the
        // CLI (root) and Apache (uid 1000) both write to it.
        $cacheDir = FS_FOLDER . '/MyFiles/Cache';
        if (false === is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }
        @chmod($cacheDir, 0777);

        parent::newDoc($title, $idformat, self::DOC_LANG);
    }

    /**
     * Draws the whole invoice. Replaces PDFExport::addBusinessDocPage() rather
     * than hooking into it: the page is composed here, section by section.
     */
    public function addBusinessDocPage($model): bool
    {
        if ($this->pdf === null) {
            $this->documentTitle = self::documentTitle($this->issuerName((int)$model->idempresa), (string)$model->codigo);
        }

        if (null === $this->format) {
            $this->format = $this->getDocumentFormat($model);
        }
        if ($this->pdf === null) {
            $this->newPage();
        } else {
            $this->pdf->ezNewPage();
            $this->insertedHeader = false;
        }
        if ($this->documentTitle !== '') {
            $this->pdf->addInfo('Title', $this->documentTitle);
        }

        $client = $model->getSubject();
        $this->lang = self::primaryLanguage($client->langcode ?? null);

        $empresa = new Empresa();
        $empresa->load((int)$model->idempresa);

        $this->drawHeaderBand($model, $empresa);
        $this->drawMetaStrip($model);
        $this->drawParties($model, $empresa, $client);
        $this->drawLines($model);
        $this->drawTotals($model);
        $this->drawPayment($model);
        $this->drawFooter($empresa);

        if (in_array($model->modelClassName(), ['FacturaCliente', 'FacturaProveedor']) && $model->editable) {
            $this->pdf->setColor(200, 0, 0);
            $this->pdf->addText(0, 230, 15, $this->i18n->trans('sketch-invoice-warning'), 600, 'center', -35);
            $this->pdf->setColor(0, 0, 0);
        }

        // do not continue with export
        return false;
    }

    /**
     * Download name. ExportBase::$fileName is private and write-once, so the
     * name is overridden on the response. Quotes and path separators are
     * stripped; spaces stay: "Arlem Gomez - Invoice F2600010.pdf".
     */
    public function show(\FacturaScripts\Core\Response &$response)
    {
        parent::show($response);
        if ($this->documentTitle !== '') {
            $safe = str_replace(['"', '/', '\\', "\r", "\n"], '', $this->documentTitle);
            $response->headers->set('Content-Disposition', 'inline; filename="' . $safe . '.pdf"');
        }
    }

    /** "<issuer> - Invoice <code>"; the issuer is dropped when unknown. */
    public static function documentTitle(string $issuer, string $code): string
    {
        $issuer = trim($issuer);
        $code = trim($code);
        return ($issuer === '' ? '' : $issuer . ' - ') . 'Invoice' . ($code === '' ? '' : ' ' . $code);
    }

    private function issuerName(int $idempresa): string
    {
        $empresa = new Empresa();
        return $idempresa > 0 && $empresa->load($idempresa) ? (string)$empresa->nombre : '';
    }

    // ------------------------------------------------------------------
    // Number / date formatting (static + pure, unit-tested)
    // ------------------------------------------------------------------

    /**
     * "$3,000.00" / "€2,674.99" in English, "3 000,00 $" / "2 674,99 €" in
     * French. Separators follow the document language, not the instance's UI
     * locale (Tools::number() would print the French UI's format on an English
     * document).
     */
    public static function money(float $amount, string $currency, string $lang = 'en_EN'): string
    {
        $symbols = ['USD' => '$', 'EUR' => "\u{20AC}", 'GBP' => "\u{00A3}"];
        $symbol = $symbols[$currency] ?? $currency;
        $sign = $amount < 0 ? '-' : '';
        $abs = abs($amount);

        if ($lang === 'fr_FR') {
            return $sign . number_format($abs, 2, ',', ' ') . ' ' . $symbol;
        }
        $prefix = isset($symbols[$currency]) ? $symbol : $symbol . ' ';
        return $sign . $prefix . number_format($abs, 2, '.', ',');
    }

    /**
     * Converts a face amount to EUR for display, with the document's own rate
     * (totaleuros = total / tasaconv), so every EUR figure ties to accounting.
     */
    public static function toEur(float $amount, float $rate): float
    {
        return $rate > 0 ? round($amount / $rate, 2) : 0.0;
    }

    /** "20 October 2026" / "20 octobre 2026"; unparseable input is returned as is. */
    public static function longDate(?string $date, string $lang = 'en_EN'): string
    {
        $value = trim((string)$date);
        if ($value === '') {
            return '';
        }
        $parsed = null;
        foreach (['Y-m-d', 'd-m-Y', 'Y-m-d H:i:s', 'd-m-Y H:i:s'] as $format) {
            $candidate = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($candidate !== false && $candidate->format($format) === $value) {
                $parsed = $candidate;
                break;
            }
        }
        if (null === $parsed) {
            return $value;
        }
        $months = self::MONTHS[$lang] ?? self::MONTHS['en_EN'];
        $day = $lang === 'fr_FR' && (int)$parsed->format('j') === 1 ? '1er' : $parsed->format('j');
        return $day . ' ' . $months[(int)$parsed->format('n') - 1] . ' ' . $parsed->format('Y');
    }

    // ------------------------------------------------------------------
    // Drawing primitives
    // ------------------------------------------------------------------

    private function contentWidth(): float
    {
        return $this->pdf->ez['pageWidth'] - 2 * self::MARGIN;
    }

    private function fill(float $x, float $y, float $w, float $h, array $rgb): void
    {
        $this->pdf->setColor($rgb[0], $rgb[1], $rgb[2], 1);
        $this->pdf->filledRectangle($x, $y, $w, $h);
    }

    private function hline(float $x1, float $x2, float $y, array $rgb, float $width = 0.6): void
    {
        $this->pdf->setStrokeColor($rgb[0], $rgb[1], $rgb[2], 1);
        $this->pdf->setLineStyle($width);
        $this->pdf->line($x1, $y, $x2, $y);
    }

    /** Text, optionally right/centre aligned inside a box of width $w starting at $x. */
    private function text(float $x, float $y, float $size, string $text, array $rgb, string $align = 'left', float $w = 0, bool $bold = false): void
    {
        $this->pdf->setColor($rgb[0], $rgb[1], $rgb[2], 1);
        $text = Tools::fixHtml($text);
        if ($bold) {
            $text = '<b>' . $text . '</b>';
        }
        if ($align !== 'left' && $w > 0) {
            // Cezpdf aligns by its own width measurement, which has no metric
            // for the euro sign (WinAnsi 0x80 is missing from the built-in
            // Helvetica tables) and so lands every EUR amount a glyph too far
            // right. Measure the euro as a digit - same 556/1000 width in
            // Helvetica - and left-place the text at the computed offset.
            $measured = $this->pdf->getTextWidth($size, str_replace("\u{20AC}", '0', $text));
            $x += $align === 'right' ? $w - $measured : ($w - $measured) / 2;
        }
        $this->pdf->addText($x, $y, $size, $text);
    }

    /**
     * Bilingual label: the document language in bold caps, Spanish underneath
     * in a lighter tone. Occupies two lines (about 16pt).
     */
    private function drawLabel(string $key, float $x, float $y, string $align = 'left', float $w = 0): void
    {
        [$primary, $es] = $this->labels($key);
        $this->text($x, $y, 6.8, mb_strtoupper($primary), self::MUTED, $align, $w, true);
        $this->text($x, $y - 8.5, 6.8, $es, self::FAINT, $align, $w);
    }

    /** Greedy word wrap using the PDF's own font metrics. @return string[] */
    private function wrap(string $text, float $size, float $width): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', trim($text)) as $paragraph) {
            $current = '';
            foreach (preg_split('/\s+/u', $paragraph) as $word) {
                $try = $current === '' ? $word : $current . ' ' . $word;
                if ($current !== '' && $this->pdf->getTextWidth($size, Tools::fixHtml($try)) > $width) {
                    $lines[] = $current;
                    $current = $word;
                } else {
                    $current = $try;
                }
            }
            $lines[] = $current;
        }
        return $lines;
    }

    private function newContentPage(): void
    {
        $this->pdf->ezNewPage();
        $this->cy = $this->pdf->ez['pageHeight'] - 56;
    }

    // ------------------------------------------------------------------
    // Sections
    // ------------------------------------------------------------------

    private function drawHeaderBand($model, Empresa $empresa): void
    {
        $pw = $this->pdf->ez['pageWidth'];
        $ph = $this->pdf->ez['pageHeight'];
        $bandH = 112.0;
        $x = self::MARGIN;
        $boxW = $pw - 2 * self::MARGIN;

        $this->fill(0, $ph - $bandH, $pw, $bandH, self::NAVY);
        $this->fill(0, $ph - $bandH - 3, $pw, 3, self::ACCENT);

        $name = (string)$empresa->nombre;
        $this->text($x, $ph - 56, mb_strlen($name) > 22 ? 17 : 21, $name, self::WHITE, 'left', 0, true);
        [$a, $aes] = $this->labels('autonomo');
        $this->text($x, $ph - 74, 7.4, mb_strtoupper($a) . '  ·  ' . mb_strtoupper($aes), self::ACCENT_SOFT);

        [$inv, $es] = $this->labels('invoice');
        $this->text($x, $ph - 48, 24, $inv, self::WHITE, 'right', $boxW, true);
        $this->text($x, $ph - 64, 9, $es, self::ACCENT_SOFT, 'right', $boxW);
        $this->text($x, $ph - 88, 13, (string)$model->codigo, self::WHITE, 'right', $boxW, true);

        $this->cy = $ph - $bandH - 3 - 26;
    }

    /** Invoice date | due date | exchange rate. */
    private function drawMetaStrip($model): void
    {
        $x = self::MARGIN;
        $y = $this->cy;
        $foreign = $this->isForeign($model);
        $cols = $foreign ? [0, 150, 300] : [0, 200];

        $this->drawLabel('date', $x + $cols[0], $y);
        $this->text($x + $cols[0], $y - 24, 10.5, self::longDate((string)$model->fecha, $this->lang), self::INK, 'left', 0, true);

        $this->drawLabel('due', $x + $cols[1], $y);
        $this->text($x + $cols[1], $y - 24, 10.5, self::longDate($this->dueDate($model), $this->lang), self::INK, 'left', 0, true);

        if ($foreign) {
            $this->drawLabel('rate', $x + $cols[2], $y);
            $this->text(
                $x + $cols[2],
                $y - 24,
                10.5,
                '1 EUR = ' . number_format((float)$model->tasaconv, 4, $this->lang === 'fr_FR' ? ',' : '.', '') . ' ' . $model->coddivisa,
                self::INK,
                'left',
                0,
                true
            );
            $source = $this->rateSourceLine($model);
            if ($source !== '') {
                $this->text($x + $cols[2], $y - 35, 7.2, $source, self::MUTED);
            }
        }

        $this->cy = $y - 54;
    }

    private function drawParties($model, Empresa $empresa, $client): void
    {
        $gap = 14.0;
        $w = ($this->contentWidth() - $gap) / 2;
        $x = self::MARGIN;

        $from = [[Tools::fixHtml((string)$empresa->nombre), true]];
        foreach ($this->companyLines($empresa) as $line) {
            $from[] = [$line, false];
        }

        $to = [[Tools::fixHtml((string)($model->nombrecliente ?: ($client->nombre ?? ''))), true]];
        foreach ($this->clientLines($model, $client) as $line) {
            $to[] = [$line, false];
        }

        $rows = max(count($from), count($to));
        $h = 40 + $rows * 11.5;
        $top = $this->cy;

        foreach ([[$x, 'from', $from], [$x + $w + $gap, 'billto', $to]] as [$bx, $key, $lines]) {
            $this->fill($bx, $top - $h, $w, $h, self::CARD);
            $this->fill($bx, $top - $h, 2.5, $h, self::ACCENT);
            $this->drawLabel($key, $bx + 14, $top - 14);
            $ly = $top - 40;
            foreach ($lines as [$line, $bold]) {
                $this->text($bx + 14, $ly, $bold ? 10 : 8.4, $line, $bold ? self::INK : self::MUTED, 'left', 0, $bold);
                $ly -= $bold ? 14 : 11.5;
            }
        }

        $this->cy = $top - $h - 26;
    }

    private function drawLines($model): void
    {
        $x = self::MARGIN;
        $total = $this->contentWidth();
        $foreign = $this->isForeign($model);
        $cur = (string)$model->coddivisa;
        $rate = (float)$model->tasaconv;

        // columns: description | qty | unit price | amount
        $qtyX = $x + $total * 0.50;
        $qtyW = $total * 0.08;
        $unitX = $x + $total * 0.58;
        $unitW = $total * 0.20;
        $amtX = $x + $total * 0.78;
        $amtW = $total * 0.22;
        $descW = $total * 0.46;

        $y = $this->cy;
        $this->drawLabel('description', $x, $y);
        $this->drawLabel('qty', $qtyX, $y, 'right', $qtyW);
        $this->drawLabel('unit', $unitX, $y, 'right', $unitW);
        $this->drawLabel('amount', $amtX, $y, 'right', $amtW);
        $y -= 17;
        $this->hline($x, $x + $total, $y, self::INK, 1.1);
        $y -= 6;

        foreach ($model->getLines() as $line) {
            $desc = (empty($line->referencia) ? '' : $line->referencia . ' - ') . $line->descripcion;
            $wrapped = $this->wrap($desc, 9.6, $descW);
            $rowH = max($foreign ? 34.0 : 24.0, count($wrapped) * 12.5 + 14);

            if ($y - $rowH < 300) {
                $this->newContentPage();
                $y = $this->cy;
            }

            $ty = $y - 15;
            foreach ($wrapped as $i => $part) {
                $this->text($x, $ty - $i * 12.5, 9.6, $part, self::INK);
            }
            $this->text($qtyX, $ty, 9.6, rtrim(rtrim(number_format((float)$line->cantidad, 2, '.', ''), '0'), '.'), self::INK, 'right', $qtyW);

            $unit = (float)$line->pvpunitario;
            $sum = (float)$line->pvptotal;
            $this->text($unitX, $ty, 9.6, self::money($unit, $cur, $this->lang), self::INK, 'right', $unitW);
            $this->text($amtX, $ty, 9.6, self::money($sum, $cur, $this->lang), self::INK, 'right', $amtW, true);
            if ($foreign) {
                $this->text($unitX, $ty - 12, 8, self::money(self::toEur($unit, $rate), 'EUR', $this->lang), self::MUTED, 'right', $unitW);
                $this->text($amtX, $ty - 12, 8, self::money(self::toEur($sum, $rate), 'EUR', $this->lang), self::MUTED, 'right', $amtW);
            }

            $y -= $rowH;
            $this->hline($x, $x + $total, $y, self::LINE);
        }

        $this->cy = $y - 22;
    }

    private function drawTotals($model): void
    {
        if ($this->cy < 250) {
            $this->newContentPage();
        }

        $x = self::MARGIN;
        $total = $this->contentWidth();
        $foreign = $this->isForeign($model);
        $cur = (string)$model->coddivisa;
        $rate = (float)$model->tasaconv;
        $top = $this->cy;

        // totals card, right-aligned; two number columns when foreign
        $cardW = 270.0;
        $cx = $x + $total - $cardW;
        $curRight = $foreign ? $cx + 130 : $cx + $cardW;
        $eurRight = $cx + $cardW;
        $y = $top;

        if ($foreign) {
            $this->text($cx, $y - 2, 7.2, $cur, self::MUTED, 'right', $curRight - $cx, true);
            $this->text($cx, $y - 2, 7.2, 'EUR', self::MUTED, 'right', $eurRight - $cx, true);
            $y -= 16;
        }

        $hasVat = abs((float)$model->totaliva) > 0.004;
        foreach ([['subtotal', (float)$model->neto, true], ['vat', (float)$model->totaliva, $hasVat]] as [$key, $value, $show]) {
            [$primary, $es] = $this->labels($key);
            $this->text($cx, $y, 9, $primary, self::INK);
            $labelEnd = $cx + $this->pdf->getTextWidth(9, $primary) + 5;
            if ($es !== $primary) {
                $this->text($labelEnd, $y, 7, $es, self::FAINT);
            }
            if ($show) {
                $this->text($cx, $y, 9, self::money($value, $cur, $this->lang), self::INK, 'right', $curRight - $cx);
                if ($foreign) {
                    $this->text($cx, $y, 9, self::money(self::toEur($value, $rate), 'EUR', $this->lang), self::MUTED, 'right', $eurRight - $cx);
                }
            } else {
                $na = $this->labels('na');
                $this->text($cx, $y, 7.4, mb_strtolower($na[0]) . ' · ' . mb_strtolower($na[1]), self::MUTED, 'right', $eurRight - $cx);
            }
            $y -= 17;
            $this->hline($cx, $x + $total, $y + 11, self::LINE);
        }

        // grand total bar
        $barH = 34.0;
        $y -= 4;
        $barY = $y - $barH + 10;
        $this->fill($cx, $barY, $cardW, $barH, self::NAVY);
        $this->fill($cx, $barY, 3, $barH, self::ACCENT);
        [$tp, $te] = $this->labels('total');
        $this->text($cx + 14, $y - 6, 10.5, $tp, self::WHITE, 'left', 0, true);
        $this->text($cx + 14, $y - 17, 6.8, $te, self::ACCENT_SOFT);
        $this->text($cx, $y - 8, 11, self::money((float)$model->total, $cur, $this->lang), self::WHITE, 'right', $curRight - $cx - ($foreign ? 0 : 12), true);
        if ($foreign) {
            $this->text($cx, $y - 8, 11, self::money(round((float)$model->totaleuros, 2), 'EUR', $this->lang), self::ACCENT_SOFT, 'right', $eurRight - $cx - 12, true);
        }

        // VAT statement and notes on the left, beside the totals
        $leftW = $total - $cardW - 26;
        $ly = $top;
        $statements = $this->vatStatements($model);
        if ($statements !== []) {
            $this->drawLabel('vatnote', $x, $ly);
            $ly -= 24;
            foreach ($statements as [$tag, $statement]) {
                foreach ($this->wrap($statement, 7.6, $leftW - 18) as $i => $part) {
                    if ($i === 0) {
                        $this->text($x, $ly, 7, $tag, self::ACCENT, 'left', 0, true);
                    }
                    $this->text($x + 18, $ly, 7.6, $part, self::MUTED);
                    $ly -= 10;
                }
                $ly -= 3;
            }
        }
        $notes = $this->customNotes($model);
        if ($notes !== '') {
            $ly -= 4;
            $this->drawLabel('notes', $x, $ly);
            $ly -= 24;
            foreach ($this->wrap($notes, 7.6, $leftW) as $part) {
                $this->text($x, $ly, 7.6, $part, self::MUTED);
                $ly -= 10;
            }
        }

        $this->cy = min($barY, $ly) - 28;
    }

    private function drawPayment($model): void
    {
        $account = $this->getCompanyBankAccount((int)$model->idempresa);
        $x = self::MARGIN;
        $w = $this->contentWidth();
        $h = 74.0;

        if ($this->cy - $h < 60) {
            $this->newContentPage();
        }
        $top = $this->cy;
        $this->fill($x, $top - $h, $w, $h, self::CARD);

        $pad = 14.0;
        $x1 = $x + $pad;
        $x2 = $x + $w * 0.30;
        $x3 = $x + $w * 0.74;

        $this->drawLabel('method', $x1, $top - 14);
        [$tp, $tes] = $this->labels('transfer');
        $this->text($x1, $top - 38, 9.4, $tp, self::INK, 'left', 0, true);
        $this->text($x1, $top - 49, 7.4, $tes, self::MUTED);

        $this->drawLabel('bank', $x2, $top - 14);
        if (null !== $account) {
            $this->text($x2, $top - 38, 9.4, 'IBAN ' . $account->getIban(true), self::INK, 'left', 0, true);
            if (!empty($account->swift)) {
                $this->text($x2, $top - 49, 7.6, 'BIC ' . $account->swift, self::MUTED);
            }
        }

        $this->drawLabel('terms', $x3, $top - 14);
        [$mp, $mes] = $this->labels('monthend');
        $this->text($x3, $top - 38, 9.4, $mp . ' · ' . $mes, self::INK, 'left', 0, true);
        $due = $this->dueDate($model);
        if ($due !== '') {
            $this->text($x3, $top - 49, 7.6, self::longDate($due, $this->lang), self::MUTED);
        }

        if (null !== $account && !empty($account->descripcion)) {
            [$hp, $hs] = $this->labels('holder');
            $this->text($x2, $top - 60, 7.6, $hp . ' / ' . $hs . ': ' . Tools::fixHtml($account->descripcion), self::MUTED);
        }

        $this->cy = $top - $h - 20;
    }

    private function drawFooter(Empresa $empresa): void
    {
        $x = self::MARGIN;
        $w = $this->contentWidth();
        $y = 34.0;
        $this->hline($x, $x + $w, $y + 12, self::LINE);

        $parts = [(string)$empresa->nombre, 'autónomo'];
        foreach (['email', 'telefono1'] as $field) {
            if (!empty($empresa->{$field})) {
                $parts[] = (string)$empresa->{$field};
            }
        }
        $this->text($x, $y, 7.4, implode('  ·  ', $parts), self::MUTED);
        $this->pdf->setColor(0, 0, 0, 1);
    }

    // ------------------------------------------------------------------
    // Data helpers
    // ------------------------------------------------------------------

    /**
     * True for a document in a currency other than EUR with a usable rate: the
     * only case where the two-currency layout applies.
     */
    private function isForeign($model): bool
    {
        return !empty($model->coddivisa) && $model->coddivisa !== 'EUR' && !empty($model->tasaconv);
    }

    private function dueDate($model): string
    {
        return (string)($model->fechadevengo ?: ($model->finoferta ?? ''));
    }

    /** @return string[] */
    private function companyLines(Empresa $empresa): array
    {
        $lines = [];
        if (!empty($empresa->cifnif)) {
            $lines[] = 'NIF ' . $empresa->cifnif;
        }
        foreach ($this->addressLines($empresa->direccion, $empresa->codpostal, $empresa->ciudad, $empresa->provincia, $empresa->codpais) as $l) {
            $lines[] = $l;
        }
        foreach (['email', 'telefono1', 'web'] as $field) {
            if (!empty($empresa->{$field})) {
                $lines[] = Tools::fixHtml((string)$empresa->{$field});
            }
        }
        return $lines;
    }

    /** @return string[] */
    private function clientLines($model, $client): array
    {
        $lines = [];
        if (!empty($model->cifnif)) {
            $lines[] = ($this->lang === 'fr_FR' ? 'N° TVA ' : 'Tax ID ') . $model->cifnif;
        }
        foreach ($this->addressLines($model->direccion ?? '', $model->codpostal ?? '', $model->ciudad ?? '', $model->provincia ?? '', $model->codpais ?? '') as $l) {
            $lines[] = $l;
        }
        foreach (['telefono1', 'email', 'web'] as $field) {
            if (!empty($client->{$field})) {
                $lines[] = Tools::fixHtml((string)$client->{$field});
            }
        }
        return $lines;
    }

    /** @return string[] */
    private function addressLines($street, $zip, $city, $province, $country): array
    {
        $lines = [];
        if (!empty($street)) {
            $lines[] = Tools::fixHtml((string)$street);
        }
        $place = trim(implode(' ', array_filter([(string)$zip, (string)$city])));
        // "Dublin, Ohio" + province "Ohio" would print Ohio twice
        if (!empty($province) && stripos($place, (string)$province) === false) {
            $place .= ($place === '' ? '' : ' ') . '(' . $province . ')';
        }
        if ($place !== '') {
            $lines[] = Tools::fixHtml($place);
        }
        if (!empty($country)) {
            $pais = new Pais();
            $lines[] = Tools::fixHtml($pais->load((string)$country) ? (string)$pais->nombre : (string)$country);
        }
        return $lines;
    }

    /**
     * "ECB reference rate · 19 October 2026" from the stored rate metadata, or
     * nothing for older invoices: claiming a source we cannot prove would be a
     * guess.
     */
    private function rateSourceLine($model): string
    {
        $meta = $this->rateMeta($model);
        if (null === $meta) {
            return '';
        }
        $fr = $this->lang === 'fr_FR';
        $source = $meta['source'] === Rate::SOURCE_MANUAL
            ? ($fr ? 'Taux manuel' : 'Manual rate')
            : ($fr ? 'Taux de référence BCE' : RateToken::sourceLabel($meta['source']));
        return $source . ' · ' . self::longDate($meta['date'], $this->lang);
    }

    /**
     * Rate metadata stored for this invoice (company + code scoped, see
     * Lib\RateToken - the code alone is not unique across the two companies),
     * or null when there is none.
     *
     * @return array{date: string, source: string}|null
     */
    private function rateMeta($model): ?array
    {
        $key = RateToken::key((int)($model->idempresa ?? 0), (string)($model->codigo ?? ''));
        if ($key === '') {
            return null;
        }
        $stored = Tools::settings(RateToken::GROUP, $key, '');
        return RateToken::decode(is_string($stored) ? $stored : null);
    }

    /**
     * VAT statement in the document language and in Spanish, from the core
     * translations of the lines' `excepcioniva` codes - the legal text the rest
     * of the application uses, so none is invented here.
     *
     * @return array<int, array{0: string, 1: string}> [tag, text] pairs
     */
    private function vatStatements($model): array
    {
        $codes = [];
        foreach ($model->getLines() as $line) {
            if (!empty($line->excepcioniva)) {
                $codes[(string)$line->excepcioniva] = true;
            }
        }
        if ($codes === []) {
            return [];
        }

        $all = TaxExceptions::all();
        $out = [];
        foreach (array_keys($codes) as $code) {
            $key = $all[$code] ?? null;
            foreach ([$this->lang, 'es_ES'] as $lang) {
                $text = null === $key ? $code : $this->i18n->customTrans($lang, $key);
                $out[] = [strtoupper(substr($lang, 0, 2)), $this->printableDashes($text)];
            }
        }
        return $out;
    }

    /**
     * Free-text notes typed on the invoice. The sentence Issuer generates
     * (rate + "out of scope under arts. 68-70") is skipped: the rate is in the
     * meta strip and the legal basis in the VAT statement, in two languages.
     */
    private function customNotes($model): string
    {
        $notes = trim((string)RateToken::strip($model->observaciones ?? null));
        if ($notes === '' || preg_match('/^(ECB reference rate|Manual rate)\b.*arts\. 68-70/su', $notes) === 1) {
            return '';
        }
        return $notes;
    }

    /**
     * Replaces typographic dashes with ASCII ones. Cezpdf's WinAnsi handling
     * does not round-trip the en dash ("Arts. 68-70" extracts as "6870").
     */
    private function printableDashes(?string $text): string
    {
        return str_replace(["\u{2013}", "\u{2014}"], '-', (string)$text);
    }

    /**
     * The company's bank account to print, or null. cuentasbanco has no holder
     * column (it lives in descripcion); the lowest codcuenta with an IBAN wins
     * so the choice is reproducible.
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
