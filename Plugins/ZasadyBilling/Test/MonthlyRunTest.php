<?php

use FacturaScripts\Plugins\ZasadyBilling\Lib\MonthlyRun;
use FacturaScripts\Plugins\ZasadyBilling\Lib\Rate;
use PHPUnit\Framework\TestCase;

/**
 * Guard against the two ways the monthly sweep can silently lose a month: a
 * missed billing day that is never retried, and a business day invoiced with an
 * older rate without anyone noticing.
 *
 * Only the decision functions are covered here: runFor() needs a database, and
 * the acceptance run exercises it end to end.
 */
final class MonthlyRunTest extends TestCase
{
    public function testNoWarningWhenTheRateIsForTheInvoiceDate(): void
    {
        $rate = new Rate(1.146, '2026-09-18', Rate::SOURCE_ECB);

        $this->assertSame('', MonthlyRun::rateDateWarning($rate, '2026-09-18'));
    }

    public function testNoWarningWhenTheInvoiceDateIsNotABusinessDay(): void
    {
        // 19-09-2026 is a Saturday: the ECB published nothing that day, so
        // using Friday's rate is the documented rule (§4.2), not a gap.
        $rate = new Rate(1.146, '2026-09-18', Rate::SOURCE_ECB);

        $this->assertSame('', MonthlyRun::rateDateWarning($rate, '2026-09-19'));
    }

    public function testWarnsWhenABusinessDayUsesAnOlderRate(): void
    {
        // 17-09-2026 is a Thursday, so a rate from the 16th means the lookup
        // ran before publication: legal to issue, but the operator must see it.
        $rate = new Rate(1.146, '2026-09-16', Rate::SOURCE_ECB);
        $warning = MonthlyRun::rateDateWarning($rate, '2026-09-17');

        $this->assertStringContainsString('WARNING', $warning);
        $this->assertStringContainsString('2026-09-17', $warning);
        $this->assertStringContainsString('2026-09-16', $warning);
    }
}
