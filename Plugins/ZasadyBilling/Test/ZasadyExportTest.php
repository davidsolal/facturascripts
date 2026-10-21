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

    /** @dataProvider languages */
    public function testPrimaryLanguageComesFromTheClient(?string $langcode, string $expected, string $pair): void
    {
        $this->assertSame($expected, ZasadyExport::primaryLanguage($langcode));
        $this->assertSame($pair, ZasadyExport::pairLabel($langcode));
    }

    public static function languages(): array
    {
        return [
            'unset client defaults to EN/ES' => [null, 'en_EN', 'EN/ES'],
            'empty string' => ['', 'en_EN', 'EN/ES'],
            'english' => ['en_EN', 'en_EN', 'EN/ES'],
            'french' => ['fr_FR', 'fr_FR', 'FR/ES'],
            'french, other region + case' => [' FR_be ', 'fr_FR', 'FR/ES'],
            'language with no label set yet' => ['de_DE', 'en_EN', 'EN/ES'],
            'spanish client still gets the default pair' => ['es_ES', 'en_EN', 'EN/ES'],
        ];
    }

    public function testMoneyFollowsTheDocumentLanguage(): void
    {
        $this->assertSame('$3,000.00', ZasadyExport::money(3000, 'USD'));
        $this->assertSame("\u{20AC}2,674.99", ZasadyExport::money(2674.99, 'EUR'));
        $this->assertSame('-$5.50', ZasadyExport::money(-5.5, 'USD'));
        $this->assertSame('5 000,00 $', ZasadyExport::money(5000, 'USD', 'fr_FR'));
        $this->assertSame("4 458,31 \u{20AC}", ZasadyExport::money(4458.31, 'EUR', 'fr_FR'));
    }

    public function testEurConversionUsesTheDocumentRate(): void
    {
        $this->assertSame(2674.99, ZasadyExport::toEur(3000, 1.1215));
        $this->assertSame(4458.31, ZasadyExport::toEur(5000, 1.1215));
        $this->assertSame(0.0, ZasadyExport::toEur(100, 0.0));
    }

    public function testLongDate(): void
    {
        $this->assertSame('20 October 2026', ZasadyExport::longDate('20-10-2026'));
        $this->assertSame('20 October 2026', ZasadyExport::longDate('2026-10-20'));
        $this->assertSame('20 octobre 2026', ZasadyExport::longDate('2026-10-20', 'fr_FR'));
        $this->assertSame('1er mars 2027', ZasadyExport::longDate('01-03-2027', 'fr_FR'));
        $this->assertSame('1 March 2027', ZasadyExport::longDate('01-03-2027'));
        $this->assertSame('', ZasadyExport::longDate(null));
        $this->assertSame('not a date', ZasadyExport::longDate('not a date'));
        // 31 February must not silently roll over to March
        $this->assertSame('31-02-2026', ZasadyExport::longDate('31-02-2026'));
    }
}
