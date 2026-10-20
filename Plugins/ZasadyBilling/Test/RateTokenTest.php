<?php

use FacturaScripts\Plugins\ZasadyBilling\Lib\Rate;
use FacturaScripts\Plugins\ZasadyBilling\Lib\RateToken;
use PHPUnit\Framework\TestCase;

/**
 * The stored metadata is the only durable record of *which* rate an invoice
 * used: the printed rate line must show the publication date and the source,
 * and both were previously derived from the invoice date and a hardcoded
 * "ECB".
 *
 * encode()/decode() are the Settings value's wire format and are pure, so the
 * store/read round-trip is covered here without a database. The actual
 * Tools::settingsSet()/settingsSave() -> Tools::settings() round-trip needs the
 * settings table and is covered by the acceptance re-render, not by this test.
 *
 * The legacy-token helpers are pinned too: they are what keeps already-issued
 * invoices from printing a `[zasady-rate]` blob, and what the one-off migration
 * uses to move the metadata into Settings.
 */
final class RateTokenTest extends TestCase
{
    public function testEncodeProducesTheDocumentedCompactJson(): void
    {
        $rate = new Rate(1.146, '2026-09-18', Rate::SOURCE_ECB);

        $this->assertSame(
            '{"date":"2026-09-18","source":"ecb"}',
            RateToken::encode($rate)
        );
    }

    public function testEncodeRefusesValuesDecodeCouldNotUse(): void
    {
        // A non-ISO date cannot be printed as a rate date, so it must not be
        // persisted at all: no entry means the documented invoice-date fallback.
        $this->assertNull(RateToken::encode(new Rate(1.146, '18/09/2026', Rate::SOURCE_ECB)));
        $this->assertNull(RateToken::encode(new Rate(1.146, '2026-09-18', '')));
    }

    public function testDecodeRoundTripsDateAndSource(): void
    {
        $rate = new Rate(1.146, '2026-09-18', Rate::SOURCE_ECB);

        $this->assertSame(
            ['date' => '2026-09-18', 'source' => 'ecb'],
            RateToken::decode(RateToken::encode($rate))
        );
    }

    public function testDecodeKeepsTheManualSource(): void
    {
        $rate = new Rate(1.15, '2026-09-19', Rate::SOURCE_MANUAL);

        $decoded = RateToken::decode(RateToken::encode($rate));

        $this->assertNotNull($decoded);
        $this->assertSame('manual', $decoded['source']);
        $this->assertSame('2026-09-19', $decoded['date']);
    }

    public function testDecodeReturnsNullForAnInvoiceWithoutMetadata(): void
    {
        // Invoices issued before the metadata moved to Settings have no entry:
        // the export falls back to the invoice date and omits the source.
        $this->assertNull(RateToken::decode(null));
        $this->assertNull(RateToken::decode(''));
        $this->assertNull(RateToken::decode('   '));
    }

    public function testDecodeRejectsMalformedValues(): void
    {
        $this->assertNull(RateToken::decode('not json'));
        $this->assertNull(RateToken::decode('{"date":"18/09/2026","source":"ecb"}'));
        $this->assertNull(RateToken::decode('{"date":"2026-09-18","source":""}'));
        $this->assertNull(RateToken::decode('{"date":"2026-09-18"}'));
        $this->assertNull(RateToken::decode('{"date":20260918,"source":"ecb"}'));
        $this->assertNull(RateToken::decode('["2026-09-18","ecb"]'));
    }

    public function testKeyIsTheInvoiceCode(): void
    {
        $this->assertSame('F2600010', RateToken::key('F2600010'));
        $this->assertSame('F2600010', RateToken::key(' F2600010 '));
        $this->assertSame('', RateToken::key(''));
    }

    public function testParseLegacyReadsTheHistoricalTokenIncludingItsEscapedForm(): void
    {
        // What Issuer used to append...
        $plain = 'ECB reference rate of 2026-09-18. [zasady-rate]'
            . '{"date":"2026-09-18","source":"ecb"}[/zasady-rate]';
        // ...and what BusinessDocument::test() (Tools::noHtml) actually stored.
        $stored = 'ECB reference rate of 2026-09-18. [zasady-rate]'
            . '{&quot;date&quot;:&quot;2026-09-18&quot;,&quot;source&quot;:&quot;ecb&quot;}'
            . '[/zasady-rate]';

        $this->assertSame(['date' => '2026-09-18', 'source' => 'ecb'], RateToken::parseLegacy($plain));
        $this->assertSame(['date' => '2026-09-18', 'source' => 'ecb'], RateToken::parseLegacy($stored));
        $this->assertNull(RateToken::parseLegacy('ECB rate 2026-09-18 (1 EUR = 1.146 USD).'));
        $this->assertNull(RateToken::parseLegacy(null));
    }

    public function testStripRemovesTheLegacyTokenAndItsOwnWhitespace(): void
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
