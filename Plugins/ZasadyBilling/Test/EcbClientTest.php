<?php

use FacturaScripts\Plugins\ZasadyBilling\Lib\EcbClient;
use PHPUnit\Framework\TestCase;

final class EcbClientTest extends TestCase
{
    private const CSV = "TIME_PERIOD,OBS_VALUE\n2026-09-14,1.1551\n2026-09-15,1.1539\n2026-09-18,1.146\n";

    // Two-row slice of the real ECB "csvdata" wide SDMX export (field order and sample values
    // verified against the live endpoint 2026-10-20): TIME_PERIOD is column 6, OBS_VALUE is
    // column 7, not 0/1. This proves parseCsv() locates columns by header name rather than
    // assuming a fixed position.
    private const WIDE_CSV = "KEY,FREQ,CURRENCY,CURRENCY_DENOM,EXR_TYPE,EXR_SUFFIX,TIME_PERIOD,OBS_VALUE,OBS_STATUS,OBS_CONF,OBS_PRE_BREAK,OBS_COM,TIME_FORMAT,COLLECTION,COMPILING_ORG,DISS_ORG,DECIMALS,UNIT,UNIT_MULT,TITLE\n"
        . "EXR.D.USD.EUR.SP00.A,D,USD,EUR,SP00,A,2026-10-19,1.1225,A,F,,,P1D,A,4F0,4F0,4,US dollar,0,ECB reference exchange rate - US dollar/Euro\n";

    public function testUsesThePublicationOnTheDate(): void
    {
        $r = EcbClient::parseCsv(self::CSV, '2026-09-18');
        $this->assertNotNull($r);
        $this->assertSame(1.146, $r->usdPerEur);
        $this->assertSame('2026-09-18', $r->dateUsed);
        $this->assertSame('ecb', $r->source);
    }

    public function testWeekendFallsBackToPreviousPublication(): void
    {
        $r = EcbClient::parseCsv(self::CSV, '2026-09-19'); // Saturday
        $this->assertNotNull($r);
        $this->assertSame('2026-09-18', $r->dateUsed);
        $this->assertSame(1.146, $r->usdPerEur);
    }

    public function testStaleRateIsRejected(): void
    {
        $this->assertNull(EcbClient::parseCsv(self::CSV, '2026-09-30'));
    }

    public function testFindsRateByHeaderNameInWideCsv(): void
    {
        $r = EcbClient::parseCsv(self::WIDE_CSV, '2026-10-19');
        $this->assertNotNull($r);
        $this->assertSame(1.1225, $r->usdPerEur);
        $this->assertSame('2026-10-19', $r->dateUsed);
        $this->assertSame('ecb', $r->source);
    }

    // Header row present but missing TIME_PERIOD/OBS_VALUE names: exercises the
    // array_search(...) === false fallback branch, which no other fixture reaches
    // (the narrow and wide fixtures both happen to also work via name lookup).
    public function testFallsBackToPositionalColumnsWhenHeaderNamesAreMissing(): void
    {
        $csv = "DATE,VALUE\n2026-09-18,1.146\n";
        $r = EcbClient::parseCsv($csv, '2026-09-18');
        $this->assertNotNull($r);
        $this->assertSame(1.146, $r->usdPerEur);
        $this->assertSame('2026-09-18', $r->dateUsed);
        $this->assertSame('ecb', $r->source);
    }
}
