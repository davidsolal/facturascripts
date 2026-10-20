<?php

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\ZasadyBilling\Lib\Export\ZasadyExport;
use PHPUnit\Framework\TestCase;

final class ZasadyExportTest extends TestCase
{
    public function testTitleNamesTheAutonomo(): void
    {
        $this->assertSame('Arlem Gomez - Invoice F2600010', ZasadyExport::documentTitle('Arlem Gomez', 'F2600010'));
    }

    public function testTitleDropsAnUnknownIssuerInsteadOfPrintingAnEmptyPrefix(): void
    {
        $this->assertSame('Invoice F2600010', ZasadyExport::documentTitle('', 'F2600010'));
        $this->assertSame('Invoice F2600010', ZasadyExport::documentTitle('  ', ' F2600010 '));
    }

    public function testMoneyUsesEnglishSeparatorsRegardlessOfInstanceLocale(): void
    {
        $this->assertSame('$3,000.00', ZasadyExport::money(3000, 'USD'));
        $this->assertSame("\u{20AC}2,674.99", ZasadyExport::money(2674.99, 'EUR'));
        $this->assertSame('-$5.50', ZasadyExport::money(-5.5, 'USD'));
    }

    public function testEurConversionUsesTheDocumentRate(): void
    {
        $this->assertSame(2674.99, ZasadyExport::toEur(3000, 1.1215));
        $this->assertSame(4458.31, ZasadyExport::toEur(5000, 1.1215));
        $this->assertSame(0.0, ZasadyExport::toEur(100, 0.0));
    }
}
