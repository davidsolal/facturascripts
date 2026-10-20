<?php

use FacturaScripts\Plugins\ZasadyBilling\Lib\Rate;
use FacturaScripts\Plugins\ZasadyBilling\Lib\RateToken;
use PHPUnit\Framework\TestCase;

/**
 * The token is the only durable record of *which* rate an invoice used: the
 * printed rate line must show the publication date and the source, and both
 * were previously derived from the invoice date and a hardcoded "ECB". These
 * tests pin the wire format, the fallback for older invoices and the fact that
 * the token never reaches the printed observations.
 */
final class RateTokenTest extends TestCase
{
    public function testEmbedAppendsTheDocumentedWireFormat(): void
    {
        $rate = new Rate(1.146, '2026-09-18', Rate::SOURCE_ECB);
        $out = RateToken::embed('ECB reference rate of 2026-09-18.', $rate);

        $this->assertSame(
            'ECB reference rate of 2026-09-18. [zasady-rate]{"date":"2026-09-18","source":"ecb"}[/zasady-rate]',
            $out
        );
    }

    public function testParseRoundTripsDateAndSource(): void
    {
        $rate = new Rate(1.146, '2026-09-18', Rate::SOURCE_ECB);
        $parsed = RateToken::parse(RateToken::embed('text', $rate));

        $this->assertSame(['date' => '2026-09-18', 'source' => 'ecb'], $parsed);
    }

    public function testParseReportsManualSource(): void
    {
        $rate = new Rate(1.15, '2026-09-19', Rate::SOURCE_MANUAL);
        $parsed = RateToken::parse(RateToken::embed('text', $rate));

        $this->assertNotNull($parsed);
        $this->assertSame('manual', $parsed['source']);
    }

    public function testParseReturnsNullWithoutTokenSoCallersFallBack(): void
    {
        // Invoices issued before this token existed must still render: the
        // export falls back to the invoice date and omits the source label.
        $this->assertNull(RateToken::parse('ECB rate 2026-09-18 (1 EUR = 1.146 USD).'));
        $this->assertNull(RateToken::parse(''));
        $this->assertNull(RateToken::parse(null));
    }

    public function testParseHandlesTheHtmlEscapedFormThatCoreStores(): void
    {
        // BusinessDocument::test() applies Tools::noHtml() to observaciones, so
        // what comes back from the database has &quot; instead of ". This is the
        // shape that actually reaches the PDF exporter.
        $stored = 'ECB reference rate of 2026-09-18. [zasady-rate]'
            . '{&quot;date&quot;:&quot;2026-09-18&quot;,&quot;source&quot;:&quot;ecb&quot;}'
            . '[/zasady-rate]';

        $this->assertSame(['date' => '2026-09-18', 'source' => 'ecb'], RateToken::parse($stored));
        $this->assertSame('ECB reference rate of 2026-09-18.', RateToken::strip($stored));
    }

    public function testParseRejectsMalformedTokens(): void
    {
        $this->assertNull(RateToken::parse('[zasady-rate]not json[/zasady-rate]'));
        $this->assertNull(RateToken::parse('[zasady-rate]{"date":"18/09/2026","source":"ecb"}[/zasady-rate]'));
        $this->assertNull(RateToken::parse('[zasady-rate]{"date":"2026-09-18","source":""}[/zasady-rate]'));
        $this->assertNull(RateToken::parse('[zasady-rate]{"date":"2026-09-18"}[/zasady-rate]'));
    }

    public function testStripRemovesTheTokenAndItsOwnWhitespace(): void
    {
        $stripped = RateToken::strip(
            'Manual rate (1 EUR = 1.1500 USD). [zasady-rate]{"date":"2026-09-19","source":"manual"}[/zasady-rate]'
        );

        $this->assertSame('Manual rate (1 EUR = 1.1500 USD).', $stripped);
        $this->assertStringNotContainsString('zasady-rate', $stripped);
    }

    public function testStripLeavesPlainTextUntouched(): void
    {
        $this->assertSame('Nothing to strip.', RateToken::strip('Nothing to strip.'));
        $this->assertSame('', RateToken::strip(null));
    }

    public function testSourceLabelNeverClaimsEcbForAManualOverride(): void
    {
        $this->assertSame('ECB reference rate', RateToken::sourceLabel(Rate::SOURCE_ECB));
        $this->assertSame('manual rate', RateToken::sourceLabel(Rate::SOURCE_MANUAL));
        // Unknown sources are printed as-is, never silently labelled ECB.
        $this->assertSame('broker rate', RateToken::sourceLabel('broker'));
    }
}
