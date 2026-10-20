<?php

namespace FacturaScripts\Plugins\ZasadyBilling\Lib\Export;

use FacturaScripts\Core\Lib\Export\PDFExport;
use FacturaScripts\Core\Tools;

/**
 * Bilingual (EN/ES) invoice export. Renders the stock FacturaScripts PDF
 * unchanged (header/body/footer all come from the core class) and appends a
 * single extra line under the header with the stored exchange rate and the
 * EUR equivalent of the document total.
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
}
