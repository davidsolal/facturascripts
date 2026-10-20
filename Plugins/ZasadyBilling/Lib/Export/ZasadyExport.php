<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2017-2025 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\ZasadyBilling\Lib\Export;

use FacturaScripts\Core\Lib\Export\PDFExport;

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

        // Literal bilingual text for this line: it has no equivalent core
        // translation key (checked en_EN.json / es_ES.json), and inventing a
        // new i18n key for a two-language client who reads both anyway adds
        // maintenance cost for no benefit. 'date', 'total' etc. elsewhere in
        // the PDF already come from core's own trans() calls.
        $this->pdf->ezText(
            sprintf(
                'Exchange rate / Tipo de cambio (%s): 1 EUR = %s %s     Total EUR / Total en EUR: %s EUR',
                $model->fecha,
                number_format((float)$model->tasaconv, 4, ',', ' '),
                $model->coddivisa,
                number_format((float)$model->totaleuros, 2, ',', ' ')
            ),
            self::FONT_SIZE
        );
    }
}
